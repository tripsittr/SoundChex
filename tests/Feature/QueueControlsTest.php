<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace Tests\Feature;

use App\Services\QueueControl;
use App\Services\QueueInspector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Starting, stopping and clearing background work (#508).
 *
 * The dashboard could say 7,018 jobs were waiting and offer nothing to do about
 * it. Pausing meant killing a worker that a LaunchAgent immediately restarts,
 * and clearing a stuck queue meant `DELETE FROM jobs` by hand.
 */
class QueueControlsTest extends TestCase
{
    use RefreshDatabase;

    private function queueJob(string $display = 'App\\Jobs\\EnrichMediaItemJob', ?int $reservedAt = null): int
    {
        return DB::table('jobs')->insertGetId([
            'queue' => 'default',
            'payload' => json_encode(['displayName' => $display, 'job' => 'Illuminate\\Queue\\CallQueuedHandler@call']),
            'attempts' => 0,
            'reserved_at' => $reservedAt,
            'available_at' => now()->timestamp,
            'created_at' => now()->timestamp,
        ]);
    }

    /* --------------------------------------------------------- pause ---- */

    public function test_pausing_stops_the_worker_taking_the_next_job(): void
    {
        // This is what makes the button mean anything: `Queue::looping`
        // returning false stops the loop reserving work.
        $control = app(QueueControl::class);

        $this->assertFalse($control->isPaused());

        $control->pause();

        $this->assertTrue($control->isPaused());
    }

    public function test_a_pause_lapses_on_its_own(): void
    {
        // A forgotten pause must not wedge the queue forever -- a week of no
        // enrichment looks like the app is broken.
        app(QueueControl::class)->pause();

        $this->assertNotNull(app(QueueControl::class)->pausedUntil());
        $this->assertTrue(app(QueueControl::class)->pausedUntil() > now());
    }

    public function test_resuming_clears_it_immediately(): void
    {
        $control = app(QueueControl::class);

        $control->pause();
        $control->resume();

        $this->assertFalse($control->isPaused());
        $this->assertNull($control->pausedUntil());
    }

    public function test_an_unreadable_cache_does_not_wedge_the_queue(): void
    {
        // The safe direction is to keep working: a stuck pause looks like the
        // app is broken, where an ignored pause is merely annoying.
        Cache::shouldReceive('get')->andThrow(new \RuntimeException('cache gone'));

        $this->expectNotToPerformAssertions();

        try {
            app(QueueControl::class)->isPaused();
        } catch (\Throwable) {
            $this->fail('A cache failure propagated out of isPaused().');
        }
    }

    /* -------------------------------------------------------- cancel ---- */

    public function test_discarding_removes_queued_work(): void
    {
        $this->queueJob();
        $this->queueJob();

        $discarded = app(QueueControl::class)->cancel();

        $this->assertSame(2, $discarded);
        $this->assertSame(0, DB::table('jobs')->count());
    }

    public function test_a_job_a_worker_is_running_is_left_alone(): void
    {
        // `reserved_at` means the bytes may already be moving. Deleting the row
        // would not stop that -- it would only lose the record of what is
        // running.
        $this->queueJob();
        $this->queueJob(reservedAt: now()->timestamp);

        $discarded = app(QueueControl::class)->cancel();

        $this->assertSame(1, $discarded);
        $this->assertSame(1, DB::table('jobs')->count(), 'A running job was discarded.');
    }

    public function test_discarding_one_kind_leaves_the_others(): void
    {
        $this->queueJob('App\\Jobs\\EnrichMediaItemJob');
        $this->queueJob('App\\Jobs\\TranscodeMediaJob');

        app(QueueControl::class)->cancel('App\\Jobs\\EnrichMediaItemJob');

        $this->assertSame(1, DB::table('jobs')->count());
        $this->assertStringContainsString('Transcode', (string) DB::table('jobs')->value('payload'));
    }

    public function test_a_wildcard_in_a_job_name_cannot_widen_the_delete(): void
    {
        // a5's review: `%` and `_` went through raw into the LIKE pattern.
        // Harmless today because the name comes from a fixed set, but a filter
        // that is safe only because of where its input happens to come from
        // stops being safe the moment somebody passes it a search box -- and
        // this method deletes rows.
        $this->queueJob('App\\Jobs\\EnrichMediaItemJob');
        $this->queueJob('App\\Jobs\\TranscodeMediaJob');

        // A bare `%` would match every payload and empty the queue.
        $discarded = app(QueueControl::class)->cancel('%');

        $this->assertSame(0, $discarded, 'A wildcard matched jobs it should not have.');
        $this->assertSame(2, DB::table('jobs')->count(), 'The queue was emptied by a wildcard.');
    }

    /* --------------------------------------------------- failed rows ---- */

    public function test_clearing_failed_records_removes_them(): void
    {
        DB::table('failed_jobs')->insert([
            'uuid' => (string) Str::uuid(),
            'connection' => 'database',
            'queue' => 'default',
            'payload' => json_encode(['displayName' => 'App\\Jobs\\EnrichMediaItemJob']),
            'exception' => 'ModelNotFoundException',
            'failed_at' => now(),
        ]);

        $cleared = app(QueueControl::class)->clearFailed();

        $this->assertSame(1, $cleared);
        $this->assertSame(0, DB::table('failed_jobs')->count());
    }

    public function test_clearing_failed_records_cancels_no_work(): void
    {
        // They are a log, not work. Clearing loses the reasons and nothing
        // else -- nothing queued should move.
        $this->queueJob();

        DB::table('failed_jobs')->insert([
            'uuid' => (string) Str::uuid(),
            'connection' => 'database',
            'queue' => 'default',
            'payload' => '{}',
            'exception' => 'x',
            'failed_at' => now(),
        ]);

        app(QueueControl::class)->clearFailed();

        $this->assertSame(1, DB::table('jobs')->count(), 'Clearing the log touched the queue.');
    }

    /* ---------------------------------------------------- throughput ---- */

    public function test_the_rate_is_null_until_there_are_two_samples(): void
    {
        // One number is not a rate, and a made-up figure is worse than an
        // empty column.
        Cache::forget('soundchex.queue.sample');

        $this->assertNull(app(QueueInspector::class)->throughput());
    }

    public function test_the_rate_is_consistent_within_one_request(): void
    {
        // The bug this guards: writing a new sample on every call made the
        // second call compare against a sample zero seconds old and return
        // null -- so minutesRemaining(), which asks again, was null every
        // time.
        Cache::put('soundchex.queue.sample', ['at' => now()->subMinutes(2)->timestamp, 'pending' => 100], now()->addHour());

        $this->queueJob();

        $inspector = app(QueueInspector::class);

        $first = $inspector->throughput();

        $this->assertNotNull($first);
        $this->assertSame($first, $inspector->throughput(), 'The rate changed within one request.');
        $this->assertNotNull($inspector->minutesRemaining(), 'The ETA was null despite a known rate.');
    }

    public function test_a_growing_queue_reports_no_rate_rather_than_a_negative_one(): void
    {
        // More work arriving than draining is true, and not a rate at which
        // anything finishes.
        Cache::put('soundchex.queue.sample', ['at' => now()->subMinutes(2)->timestamp, 'pending' => 1], now()->addHour());

        $this->queueJob();
        $this->queueJob();
        $this->queueJob();

        $this->assertSame(0.0, app(QueueInspector::class)->throughput());
        $this->assertNull(app(QueueInspector::class)->minutesRemaining());
    }
}
