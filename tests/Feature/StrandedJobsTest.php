<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Events\WorkerStarting;
use Illuminate\Queue\WorkerOptions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * A job held by a worker that no longer exists.
 *
 * `reserved_at` says a worker has the job; it does not say which worker, so
 * once that process is gone nothing can tell the row apart from work in
 * progress. The queue reconsiders it only after `retry_after`, and this app
 * sets that above the worker's own six-hour timeout on purpose — the
 * alternative is failing jobs that are still running. So every restart used to
 * cost whatever was in flight six hours of doing nothing.
 *
 * A worker starting is the one moment it is safe to say nothing is in flight,
 * because the supervisor runs exactly one.
 *
 * **Off by default since #465.** `library:pipeline-sweep` now recovers lost
 * pipeline work by stage timeout, which is safe with any number of workers,
 * where this is only safe with exactly one — releasing a reservation a sibling
 * worker is part-way through runs that job twice. The mechanism stays for
 * non-pipeline jobs on a single-worker install, so these tests enable it
 * explicitly rather than relying on the default they used to get for free.
 */
class StrandedJobsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('queue.release_reservations_on_worker_start', true);
    }

    private function job(?int $reservedAt): int
    {
        return (int) DB::table('jobs')->insertGetId([
            'queue' => 'default',
            'payload' => json_encode(['displayName' => 'App\Jobs\EnrichMediaItemJob', 'data' => []]),
            'attempts' => $reservedAt === null ? 0 : 1,
            'reserved_at' => $reservedAt,
            'available_at' => now()->timestamp,
            'created_at' => now()->timestamp,
        ]);
    }

    private function workerStarts(): void
    {
        Event::dispatch(new WorkerStarting('database', 'default', new WorkerOptions));
    }

    public function test_a_worker_starting_frees_a_reserved_job(): void
    {
        $stranded = $this->job(now()->subHour()->timestamp);

        $this->workerStarts();

        $this->assertNull(
            DB::table('jobs')->where('id', $stranded)->value('reserved_at'),
            'The job should be available again.'
        );
    }

    /**
     * The attempt count stays.
     *
     * The attempt did happen, and a job that keeps killing its worker must
     * still run out of tries rather than cycling forever.
     */
    public function test_the_attempt_count_is_left_alone(): void
    {
        $stranded = $this->job(now()->subHour()->timestamp);

        $this->workerStarts();

        $this->assertSame(1, (int) DB::table('jobs')->where('id', $stranded)->value('attempts'));
    }

    public function test_an_unreserved_job_is_untouched(): void
    {
        $waiting = $this->job(null);

        $this->workerStarts();

        $row = DB::table('jobs')->where('id', $waiting)->first();

        $this->assertNull($row->reserved_at);
        $this->assertSame(0, (int) $row->attempts);
    }

    /**
     * Off, nothing is freed.
     *
     * This is the setting a deployment with several workers must use: a worker
     * starting would otherwise hand itself a job a sibling is part-way
     * through, and the job would run twice.
     */
    public function test_it_does_nothing_when_disabled(): void
    {
        config(['queue.release_reservations_on_worker_start' => false]);

        $reserved = now()->subHour()->timestamp;
        $id = $this->job($reserved);

        $this->workerStarts();

        $this->assertSame($reserved, (int) DB::table('jobs')->where('id', $id)->value('reserved_at'));
    }
}
