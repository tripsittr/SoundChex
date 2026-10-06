<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace Tests\Feature;

use App\Services\MediaTrash;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The backstop for every delete in the library (#464).
 *
 * Its job is to make a wrong delete survivable, so the cases that matter are
 * the failures: a file it cannot move must be left alone, and a restore must
 * never overwrite the copy that was kept instead.
 */
class MediaTrashTest extends TestCase
{
    use RefreshDatabase;

    private MediaTrash $trash;

    protected function setUp(): void
    {
        parent::setUp();

        $this->trash = app(MediaTrash::class);
    }

    public function test_a_discarded_file_still_exists_in_the_trash(): void
    {
        $disk = Storage::disk('local');
        $disk->put('media/library/Music/flipturn/Heavy Colors/03 Chicago.mp3', 'the audio');

        $source = $disk->path('media/library/Music/flipturn/Heavy Colors/03 Chicago.mp3');

        $trashed = $this->trash->discard($source, reason: 'duplicate resolved');

        $this->assertNotNull($trashed);
        $this->assertFileDoesNotExist($source);
        $this->assertFileExists($disk->path($trashed));
        $this->assertSame('the audio', $disk->get($trashed));
    }

    public function test_the_trash_keeps_the_original_location_so_two_same_named_files_do_not_collide(): void
    {
        // "03 Chicago.mp3" exists on many albums. Trashing by basename alone
        // would suffix the second into something unrecognisable.
        $disk = Storage::disk('local');
        $disk->put('media/library/Music/A/One/03 Chicago.mp3', 'from A');
        $disk->put('media/library/Music/B/Two/03 Chicago.mp3', 'from B');

        $first = $this->trash->discard($disk->path('media/library/Music/A/One/03 Chicago.mp3'));
        $second = $this->trash->discard($disk->path('media/library/Music/B/Two/03 Chicago.mp3'));

        $this->assertNotSame($first, $second);
        $this->assertSame('from A', $disk->get($first));
        $this->assertSame('from B', $disk->get($second));
    }

    public function test_discarding_a_file_that_is_already_gone_is_not_an_error(): void
    {
        // A caller re-running after a partial failure lands here.
        $this->assertNull($this->trash->discard(Storage::disk('local')->path('media/library/gone.mp3')));
    }

    public function test_a_restored_file_comes_back_with_its_contents(): void
    {
        $disk = Storage::disk('local');
        $disk->put('media/library/Music/A/One/track.mp3', 'the audio');

        $source = $disk->path('media/library/Music/A/One/track.mp3');
        $trashed = $this->trash->discard($source);

        $this->assertTrue($this->trash->restore($trashed, $source));
        $this->assertSame('the audio', $disk->get('media/library/Music/A/One/track.mp3'));
    }

    public function test_a_restore_refuses_to_overwrite_what_is_there(): void
    {
        // The file occupying the target may be the copy that was kept when this
        // one was trashed, so clobbering it would lose the survivor.
        $disk = Storage::disk('local');
        $disk->put('media/library/Music/A/One/track.mp3', 'the trashed one');

        $source = $disk->path('media/library/Music/A/One/track.mp3');
        $trashed = $this->trash->discard($source);

        $disk->put('media/library/Music/A/One/track.mp3', 'the one that was kept');

        $this->assertFalse($this->trash->restore($trashed, $source));
        $this->assertSame('the one that was kept', $disk->get('media/library/Music/A/One/track.mp3'));
        $this->assertFileExists($disk->path($trashed), 'The trashed copy must survive a refused restore.');
    }

    public function test_the_purge_removes_files_past_the_retention_window(): void
    {
        $disk = Storage::disk('local');

        Carbon::setTestNow('2026-09-01 12:00:00');
        $disk->put('media/library/old.mp3', 'old');
        $old = $this->trash->discard($disk->path('media/library/old.mp3'));

        Carbon::setTestNow('2026-10-05 12:00:00');
        $disk->put('media/library/new.mp3', 'new');
        $new = $this->trash->discard($disk->path('media/library/new.mp3'));

        $removed = $this->trash->purge(days: 30);

        $this->assertSame(1, $removed);
        $this->assertFileDoesNotExist($disk->path($old));
        $this->assertFileExists($disk->path($new), 'A file inside the window must be kept.');

        Carbon::setTestNow();
    }

    public function test_a_retention_of_zero_keeps_everything(): void
    {
        // The "keep until emptied by hand" setting.
        $disk = Storage::disk('local');

        Carbon::setTestNow('2020-01-01 12:00:00');
        $disk->put('media/library/ancient.mp3', 'ancient');
        $trashed = $this->trash->discard($disk->path('media/library/ancient.mp3'));

        Carbon::setTestNow('2026-10-05 12:00:00');

        $this->assertSame(0, $this->trash->purge(days: 0));
        $this->assertFileExists($disk->path($trashed));

        Carbon::setTestNow();
    }
}
