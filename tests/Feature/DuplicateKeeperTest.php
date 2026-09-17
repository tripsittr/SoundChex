<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace Tests\Feature;

use App\Enums\MediaItemType;
use App\Models\MediaItem;
use App\Models\User;
use App\Services\DuplicateDetector;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Which copy of a duplicated file survives.
 *
 * This decides which real file gets deleted from a library that is the only
 * copy of itself, so it is worth pinning rather than leaving to arrival order.
 * The oldest row used to win outright — meaning a loose copy catalogued first
 * survived while the one the organiser had deliberately filed was removed.
 */
class DuplicateKeeperTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();

        app(SettingsService::class)->set('library_detect_duplicates', true);
        app(SettingsService::class)->set('library_duplicate_action', 'review');
    }

    /*
     * Every case here needs *two* candidates for the item being checked, or
     * the preference is never exercised: with one candidate `first()` returns
     * it whatever the ordering rule is. The first version of this file had one
     * candidate per test and passed with the preference deleted.
     */

    public function test_the_filed_copy_survives_even_though_the_loose_one_came_first(): void
    {
        // Loose copy catalogued first, so the oldest-wins rule would keep it
        // and delete the filed one. That is the case this exists for.
        $looseFirst = $this->item('media/unsorted/Sunnify/Karma Police.mp3');
        $filed = $this->item('media/library/Music/Radiohead/Karma Police.mp3');

        $arriving = $this->item('media/unsorted/Inbox/Karma Police.mp3');

        $original = app(DuplicateDetector::class)->check($arriving->fresh());

        $this->assertNotNull($original, 'The copies were not recognised as duplicates.');
        $this->assertSame(
            $filed->id,
            $original->id,
            "The loose copy (#{$looseFirst->id}) was kept over the filed one (#{$filed->id}).",
        );
    }

    public function test_a_windows_filed_path_counts_as_filed(): void
    {
        // The organiser writes backslashes there — S-86. Matching only forward
        // slashes would make every Windows copy look loose and the preference
        // would silently do nothing on the machine this was written on.
        $this->item('media/unsorted/Sunnify/Karma Police.mp3');
        $filed = $this->item('media\\library\\Music\\Radiohead\\Karma Police.mp3');

        $arriving = $this->item('media/unsorted/Inbox/Karma Police.mp3');

        $original = app(DuplicateDetector::class)->check($arriving->fresh());

        $this->assertNotNull($original);
        $this->assertSame($filed->id, $original->id);
    }

    public function test_the_oldest_wins_when_neither_is_filed(): void
    {
        // Nothing to prefer, so the fallback stands: the oldest row, which is
        // the one anything else is most likely to already reference.
        $first = $this->item('media/unsorted/A/Karma Police.mp3');
        $this->item('media/unsorted/B/Karma Police.mp3');

        $arriving = $this->item('media/unsorted/C/Karma Police.mp3');

        $original = app(DuplicateDetector::class)->check($arriving->fresh());

        $this->assertNotNull($original);
        $this->assertSame($first->id, $original->id);
    }

    private function item(string $path, MediaItemType $type = MediaItemType::Music): MediaItem
    {
        $full = Storage::path($path);

        @mkdir(dirname(str_replace('\\', '/', $full)), 0755, true);
        file_put_contents(str_replace('\\', '/', $full), 'the same bytes either way');

        $item = MediaItem::create([
            'user_id' => $this->user->id,
            'type' => $type,
            'title' => 'Karma Police',
            'file_path' => $path,
        ]);

        // Not through create(): `content_hash` is deliberately not fillable, so
        // mass assignment drops it silently and both rows end up unhashed —
        // which is how the first version of this test failed for a reason that
        // had nothing to do with what it was testing.
        $item->forceFill(['content_hash' => 'identical-hash'])->saveQuietly();

        return $item->fresh();
    }
}
