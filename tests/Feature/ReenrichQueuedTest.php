<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace Tests\Feature;

use App\Enums\MediaItemType;
use App\Jobs\EnrichMediaItemJob;
use App\Models\MediaItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * `music:reenrich --queue` spreads enrichment over the background queue (S-273
 * follow-up), so a full-library re-enrich runs without blocking and without
 * burning through provider rate limits.
 */
class ReenrichQueuedTest extends TestCase
{
    use RefreshDatabase;

    private function track(string $confidence = 'none'): MediaItem
    {
        $item = MediaItem::create([
            'user_id' => User::factory()->create()->id,
            'type' => MediaItemType::Music,
            'title' => 'Song',
            'owned' => true,
            'match_confidence' => $confidence,
        ]);
        $item->musicMetadata()->create(['artist' => 'Someone']);

        return $item->fresh();
    }

    public function test_queue_dispatches_one_job_per_track(): void
    {
        Queue::fake();
        $this->track();
        $this->track();
        $this->track();

        $this->artisan('music:reenrich', ['--queue' => true])->assertSuccessful();

        Queue::assertPushed(EnrichMediaItemJob::class, 3);
    }

    public function test_queue_staggers_the_jobs(): void
    {
        Queue::fake();
        $this->track();
        $this->track();

        $this->artisan('music:reenrich', ['--queue' => true, '--stagger' => 1000])->assertSuccessful();

        // The second job is delayed relative to the first.
        $delays = [];
        Queue::assertPushed(EnrichMediaItemJob::class, function ($job) use (&$delays) {
            $delays[] = $job->delay;

            return true;
        });

        $this->assertCount(2, $delays);
        // At least one job carries a non-null delay (the stagger).
        $this->assertNotEmpty(array_filter($delays));
    }

    public function test_only_unmatched_queues_just_the_unmatched(): void
    {
        Queue::fake();
        $this->track('none');
        $this->track('exact'); // already matched — skipped

        $this->artisan('music:reenrich', ['--queue' => true, '--only-unmatched' => true])
            ->assertSuccessful();

        Queue::assertPushed(EnrichMediaItemJob::class, 1);
    }

    public function test_it_does_not_run_the_pipeline_inline_when_queued(): void
    {
        Queue::fake();
        $this->track();

        // If it ran inline it would hit the network; faking the queue proves it
        // only dispatched.
        $this->artisan('music:reenrich', ['--queue' => true])->assertSuccessful();

        Queue::assertPushed(EnrichMediaItemJob::class);
    }
}
