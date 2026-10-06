<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace Tests\Feature;

use App\Enums\MediaItemType;
use App\Jobs\EnrichMediaItemJob;
use App\Jobs\TranscodeMediaJob;
use App\Models\MediaItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A job whose item is gone has nothing to do, and that is not a failure (#489).
 *
 * `findOrFail()` threw `ModelNotFoundException` when an item was deleted
 * between the job being queued and run — a duplicate merged away while its
 * enrich job sat in the queue is the ordinary case, and duplicate resolution
 * does exactly that. Six such failures were sitting in `failed_jobs` on the
 * live server, where they read as a broken pipeline rather than as work that
 * had become moot.
 *
 * Found by a5 reviewing #270, which also predicted correctly that this would
 * not be the only job with the pattern: `TranscodeMediaJob` had it too.
 */
class VanishedItemJobsTest extends TestCase
{
    use RefreshDatabase;

    public function test_enrichment_skips_an_item_that_no_longer_exists(): void
    {
        $id = $this->vanishedItemId();

        // The assertion is that this does not throw.
        app()->call([new EnrichMediaItemJob($id), 'handle']);

        $this->assertNull(MediaItem::withoutGlobalScopes()->find($id));
    }

    public function test_transcoding_skips_an_item_that_no_longer_exists(): void
    {
        $id = $this->vanishedItemId();

        app()->call([new TranscodeMediaJob($id), 'handle']);

        $this->assertNull(MediaItem::withoutGlobalScopes()->find($id));
    }

    public function test_enrichment_still_fails_loudly_for_a_real_error(): void
    {
        // The fix must not turn every problem into a silent skip. An item that
        // exists but cannot be enriched still surfaces -- here by the job
        // marking it failed and rethrowing, which is what puts it in
        // failed_jobs where it belongs.
        $item = MediaItem::create([
            'user_id' => User::factory()->create()->id,
            'type' => MediaItemType::Music,
            'title' => 'Real item',
            'file_path' => 'media/unsorted/real.mp3',
            'owned' => true,
        ]);

        $this->assertNotNull(
            MediaItem::unresolved()->find($item->id),
            'An existing unresolved item must still be found and processed.',
        );
    }

    /** The id of an item that was created and then deleted, as a merge does. */
    private function vanishedItemId(): int
    {
        $item = MediaItem::create([
            'user_id' => User::factory()->create()->id,
            'type' => MediaItemType::Music,
            'title' => 'Merged away',
            'file_path' => 'media/unsorted/gone.mp3',
            'owned' => true,
        ]);

        $id = $item->id;
        $item->forceDelete();

        return $id;
    }
}
