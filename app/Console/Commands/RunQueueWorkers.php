<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Console\Commands;

use App\Services\QueueControl;
use Illuminate\Console\Command;
use Symfony\Component\Process\Process;

/**
 * Runs as many queue workers as the settings ask for.
 *
 * The supervisors — the Rust one, the LaunchAgent, the systemd unit — each
 * start a single `queue:work`, so the install has run exactly one job at a time
 * since it was built. On an eight-core machine with 4,366 jobs queued that is
 * one core's worth of capacity and a backlog measured in hours.
 *
 * Changing the number meant editing three supervisor templates and reinstalling.
 * This command is started *instead* of `queue:work` and manages the pool
 * itself, so the number becomes a setting the owner can change from the panel.
 *
 * **One by default, deliberately.** Enrichment is rate-limited by the services
 * it calls — MusicBrainz allows one request a second — so a second worker there
 * buys nothing and doubles the chance of tripping a limit. Hashing and
 * transcoding are the exceptions, bound by this machine rather than somebody
 * else's, which is why it is a setting rather than a constant.
 */
class RunQueueWorkers extends Command
{
    protected $signature = 'queue:workers
        {--queue=default,net,cpu,io : Which queues to serve, in priority order}
        {--timeout=21900 : Seconds a single job may take}
        {--once : Start the pool and return, for testing}';

    protected $description = 'Run the configured number of queue workers';

    /**
     * How often to notice the setting changed.
     *
     * Slow enough not to be a load test on the settings table, quick enough
     * that somebody who sets it to four does not wonder whether it worked.
     */
    private const POLL_SECONDS = 15;

    /** @var array<int, Process> */
    private array $workers = [];

    public function handle(QueueControl $control): int
    {
        $wanted = $control->concurrency();

        $this->info("Starting {$wanted} worker(s) on {$this->option('queue')}.");

        // Children are killed with this process rather than orphaned: a pool
        // whose supervisor died is worse than no pool, because the next start
        // adds a second set and nothing reaps the first.
        //
        // Only where signals exist. `SIGTERM` and `SIGINT` are **pcntl**
        // constants, and pcntl is not built into Windows PHP -- referencing
        // them there is a fatal `Undefined constant` before a single worker
        // spawns. Since `supervisor.rs` runs this as the only worker launcher,
        // that would have stopped all background processing on the Windows
        // server (a5's review, #512).
        //
        // Skipping the trap on Windows is safe: the pool is reaped when the
        // parent is killed, through the job object the process is created in.
        if (extension_loaded('pcntl')) {
            $this->trap([SIGTERM, SIGINT], function (): void {
                $this->stopAll();
                exit(0);
            });
        }

        do {
            $this->reconcile($control->concurrency());

            if ($this->option('once')) {
                // Started, proved, stopped. Returning while children run would
                // orphan them: nothing would reap them, and the next start
                // would add a second pool on top of the first. `--once` exists
                // for a test, and a test that leaves processes behind is worse
                // than no test.
                $this->stopAll();

                break;
            }

            sleep(self::POLL_SECONDS);
        } while (true);

        return self::SUCCESS;
    }

    /**
     * Brings the pool to the wanted size, and replaces anything that died.
     *
     * Scaling down lets the extra workers finish what they have in hand rather
     * than killing them: a half-written transcode is worse than a slightly
     * slow response to a settings change.
     */
    private function reconcile(int $wanted): void
    {
        // Drop the ones that exited -- crashed, or finished after a scale-down.
        $this->workers = array_values(array_filter(
            $this->workers,
            fn (Process $worker): bool => $worker->isRunning(),
        ));

        while (count($this->workers) < $wanted) {
            $this->workers[] = $this->spawn();
        }

        if (count($this->workers) > $wanted) {
            // Symfony's default signal is already SIGTERM on Unix and a
            // taskkill on Windows, so naming the constant bought nothing and
            // cost portability. `queue:work` treats SIGTERM as "finish the
            // current job and exit" rather than "die now".
            foreach (array_splice($this->workers, $wanted) as $extra) {
                $extra->stop(0);
            }
        }
    }

    private function spawn(): Process
    {
        $worker = new Process([
            PHP_BINARY,
            base_path('artisan'),
            'queue:work',
            '--queue='.$this->option('queue'),
            // One try, as every supervisor has always passed: a failed job
            // goes to `failed_jobs` with its reason rather than being retried
            // blindly.
            '--tries=1',
            '--timeout='.$this->option('timeout'),
        ], base_path());

        // No timeout on the supervisor's own view of the child: the child has
        // its own, and Symfony's default would kill a long transcode at 60s.
        $worker->setTimeout(null);
        $worker->start();

        return $worker;
    }

    private function stopAll(): void
    {
        foreach ($this->workers as $worker) {
            $worker->stop(10);
        }

        $this->workers = [];
    }
}
