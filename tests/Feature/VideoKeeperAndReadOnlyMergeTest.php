<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace Tests\Feature;

use App\Enums\MediaItemType;
use App\Models\MediaItem;
use App\Models\MovieMetadata;
use App\Models\User;
use App\Services\DuplicateDetector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Two ways merging a duplicate went wrong, both found on a real library.
 *
 * The first is destructive. `decideKeeper()` judges on bitrate, sample rate and
 * tag completeness — all of them read off `musicMetadata`, which a film does
 * not have. So every video pair came out a tie, and the bulk action broke ties
 * by keeping the higher row id: it would have deleted a 43 MB episode to keep a
 * 17 MB one, and a 41 MB episode to keep 2 MB.
 *
 * The second is why "I merged it and they came back". 285 of 2,843 files in that
 * library carry the Windows read-only attribute, arrived with it from a copy off
 * another machine. `unlink()` will not remove a read-only file on Windows, so
 * the delete failed, the row stayed pending, and the next sweep flagged it
 * again — reported as "a file was missing or the contents no longer match",
 * about a file that was present throughout.
 */
class VideoKeeperAndReadOnlyMergeTest extends TestCase
{
    use RefreshDatabase;

    private DuplicateDetector $detector;

    private User $user;

    private static int $n = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->detector = app(DuplicateDetector::class);
        $this->user = User::factory()->create();
    }

    /* ------------------------------------------------- which copy to keep --- */

    /** The bigger file wins, which is what "better" means for a video. */
    public function test_the_larger_video_copy_is_kept(): void
    {
        $small = $this->film('Bart of Darkness', str_repeat('a', 2_000));
        $large = $this->film('Bart of Darkness', str_repeat('b', 41_000));

        [$winner, $reason] = $this->detector->decideKeeper($small, $large, breakTies: true);

        $this->assertSame($large->id, $winner?->id, 'The 41 KB copy must win over the 2 KB one.');
        $this->assertSame('file_size', $reason);
    }

    /**
     * Order must not matter.
     *
     * The old tie-break kept the higher id, so which copy survived depended on
     * which was catalogued second.
     */
    public function test_the_result_does_not_depend_on_the_argument_order(): void
    {
        $small = $this->film('Lisa\'s Rival', str_repeat('a', 17_000));
        $large = $this->film('Lisa\'s Rival', str_repeat('b', 43_000));

        [$first] = $this->detector->decideKeeper($small, $large, breakTies: true);
        [$second] = $this->detector->decideKeeper($large, $small, breakTies: true);

        $this->assertSame($large->id, $first?->id);
        $this->assertSame($large->id, $second?->id);
    }

    /**
     * Two near-equal rips stay a tie, even when ties may be broken.
     *
     * For video there is no quality signal left to break one with, and choosing
     * by row id is a coin toss that deletes somebody's file. A review list that
     * is never reviewed is the thing being avoided here, not a tie.
     */
    public function test_two_similar_video_copies_are_left_for_a_person(): void
    {
        $a = $this->film('Heat', str_repeat('a', 100_000));
        $b = $this->film('Heat', str_repeat('b', 104_000)); // 4% apart

        [$winner, $reason] = $this->detector->decideKeeper($a, $b, breakTies: true);

        $this->assertNull($winner, 'A 4% difference is not a quality decision.');
        $this->assertSame('tie', $reason);
    }

    /** A missing file loses to one that is present. */
    public function test_the_copy_that_still_exists_is_kept(): void
    {
        $gone = $this->film('Casino', 'bytes');
        $here = $this->film('Casino', 'other bytes');

        @unlink($gone->absoluteFilePath());

        [$winner, $reason] = $this->detector->decideKeeper($gone, $here, breakTies: true);

        $this->assertSame($here->id, $winner?->id);
        $this->assertSame('only_copy_present', $reason);
    }

    /** Music still decides the way it always did. */
    public function test_music_is_untouched_by_this(): void
    {
        $a = $this->track('Sun', str_repeat('a', 2_000));
        $b = $this->track('Sun', str_repeat('b', 41_000));

        [, $reason] = $this->detector->decideKeeper($a, $b, breakTies: true);

        // Not file_size: music goes through bitrate, sample rate and tags, and
        // with none of them set it is a tie broken by the newer row.
        $this->assertNotSame('file_size', $reason);
    }

    /* ------------------------------------------------- deleting the loser --- */

    /**
     * A read-only copy is still deleted.
     *
     * The attribute is cleared first. Without that, `unlink()` fails on Windows
     * and the merge reports a missing file about one that is present.
     */
    public function test_a_read_only_duplicate_can_be_merged(): void
    {
        $original = $this->film('War Dogs', 'identical bytes');
        $copy = $this->film('War Dogs', 'identical bytes');

        $this->detector->check($copy);

        $copyPath = $copy->fresh()->absoluteFilePath();
        $this->assertNotNull($copyPath);

        // What the real library's files arrived as.
        chmod($copyPath, 0444);

        $this->assertTrue(
            $this->detector->merge($copy->fresh()),
            'A read-only duplicate must still merge.'
        );

        $this->assertFileDoesNotExist($copyPath);
        $this->assertFileExists($original->absoluteFilePath());
    }

    private function film(string $title, string $bytes): MediaItem
    {
        $item = $this->row($title, MediaItemType::Movie, $bytes);

        MovieMetadata::create([
            'media_item_id' => $item->id,
            'tmdb_id' => null,
            'release_year' => 2016,
        ]);

        return $item->fresh();
    }

    private function track(string $title, string $bytes): MediaItem
    {
        return $this->row($title, MediaItemType::Music, $bytes);
    }

    private function row(string $title, MediaItemType $type, string $bytes): MediaItem
    {
        self::$n++;

        $path = 'media/library/keeper-'.self::$n.($type === MediaItemType::Music ? '.flac' : '.mkv');
        Storage::disk('local')->put($path, $bytes);

        $item = MediaItem::create([
            'user_id' => $this->user->id,
            'type' => $type,
            'title' => $title,
            'file_path' => Storage::disk('local')->path($path),
            'owned' => true,
        ]);

        $this->detector->ensureHashed($item);

        return $item->fresh();
    }
}
