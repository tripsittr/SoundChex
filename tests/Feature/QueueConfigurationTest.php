<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace Tests\Feature;

use App\Jobs\DetectDuplicatesJob;
use App\Services\DuplicateDetector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionClass;
use Tests\TestCase;

/**
 * The queue's own settings, which failed three jobs on a live install.
 *
 * `retry_after` is the one piece of queue configuration that cannot be read in
 * isolation: it is only correct relative to how long a job may run. Set below
 * that, the queue decides a still-running job has been abandoned and hands it
 * to another worker, and the job then fails with MaxAttemptsExceededException
 * while the original copy is still working — a failure with no cause anywhere
 * near it.
 */
class QueueConfigurationTest extends TestCase
{
    use RefreshDatabase;

    public function test_retry_after_exceeds_the_worker_timeout(): void
    {
        $timeout = $this->supervisorWorkerTimeout();

        $this->assertGreaterThan(
            $timeout,
            (int) config('queue.connections.database.retry_after'),
            "retry_after must exceed the worker's --timeout ({$timeout}s), or the queue ".
            'retries jobs that are still running.'
        );
    }

    /**
     * No job may declare a timeout above `retry_after` either.
     *
     * The worker's ceiling covers the jobs with no timeout of their own;
     * a job that sets a longer one would reintroduce the same failure.
     */
    public function test_no_job_declares_a_timeout_above_retry_after(): void
    {
        $retryAfter = (int) config('queue.connections.database.retry_after');

        $tooLong = [];

        foreach (glob(app_path('Jobs/*.php')) ?: [] as $file) {
            if (preg_match('~public int \$timeout = (\d+);~', (string) file_get_contents($file), $m) !== 1) {
                continue;
            }

            if ((int) $m[1] >= $retryAfter) {
                $tooLong[] = basename($file, '.php').' declares '.$m[1].'s';
            }
        }

        $this->assertSame([], $tooLong, "These jobs can run longer than retry_after ({$retryAfter}s):\n".implode("\n", $tooLong));
    }

    /**
     * The worker timeout as the supervisor actually passes it.
     *
     * Read from the Rust rather than repeated here, so the two cannot drift:
     * a number copied into a test asserts only that someone once copied it.
     */
    private function supervisorWorkerTimeout(): int
    {
        $source = base_path('src-tauri/src/supervisor.rs');

        $this->assertFileExists($source, 'The supervisor is what sets the worker timeout.');

        $matched = preg_match('~--timeout=(\d+)~', (string) file_get_contents($source), $m);

        $this->assertSame(1, $matched, 'Could not find the worker --timeout in the supervisor.');

        return (int) $m[1];
    }

    /**
     * A job queued before `$type` existed must still run.
     *
     * The queue outlives an upgrade — the payload table is in the database, and
     * the database is deliberately the one thing provisioning does not replace.
     * Such a payload unserialises with no value for a property that was added
     * later, and a typed property with no value throws on access rather than
     * reading as null. One did, on a live install: "Typed property
     * DetectDuplicatesJob::$type must not be accessed before initialization".
     *
     * newInstanceWithoutConstructor reproduces that state exactly, which is
     * what unserialising an older payload does.
     */
    public function test_a_payload_from_before_the_type_property_still_runs(): void
    {
        $job = (new ReflectionClass(DetectDuplicatesJob::class))->newInstanceWithoutConstructor();

        $this->assertFalse(
            (new ReflectionClass($job))->getProperty('type')->isInitialized($job),
            'The property must be uninitialised for this test to be testing anything.'
        );

        $job->handle(app(DuplicateDetector::class));
    }
}
