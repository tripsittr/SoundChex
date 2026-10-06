<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Providers;

use App\Filesystem\WindowsSafeFilesystem;
use App\Services\CurrentProfile;
use App\Services\QueueControl;
use App\Services\ScheduleInspector;
use Composer\CaBundle\CaBundle;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskSkipped;
use Illuminate\Http\Request;
use Illuminate\Queue\Events\WorkerStarting;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Throwable;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // One per request, because it caches the profile it resolved.
        //
        // Twenty call sites do `app(CurrentProfile::class)`, and without this
        // each one built its own instance with an empty cache — so the same
        // profile was fetched from the database once per call. `/app/music`
        // ran that query 126 times, `/app` 28, for a value that cannot change
        // within a request.
        $this->app->singleton(CurrentProfile::class);

        // Windows cannot rename a file over one another thread holds open, and
        // Laravel's `replace()` is a temp-file-plus-rename. Under FrankenPHP,
        // two requests compiling the same Blade view collide on exactly that
        // and the loser returns a failed page. This swaps in a version that
        // writes in place under a lock on Windows, and is the stock
        // implementation everywhere else.
        $this->app->singleton('files', fn (): WindowsSafeFilesystem => new WindowsSafeFilesystem);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->recordScheduledRuns();
        $this->releaseStrandedJobs();
        $this->honourQueuePause();

        // A CA bundle wherever PHP forgot to bring one.
        //
        // Windows PHP ships without one, so every outbound HTTPS request —
        // transfers, TMDB, MusicBrainz, artwork, subtitles — failed with
        // "cURL error 60" until someone edited php.ini by hand (S-75). The
        // ca-bundle package finds the system bundle where one exists and
        // falls back to the pem it ships, so the same code is a no-op on
        // macOS and Linux and the fix on Windows. Applied globally because
        // every outbound call in this app goes through the Http facade.
        Http::globalOptions([
            'verify' => CaBundle::getSystemCaRootBundlePath(),
        ]);

        $this->enforceHttpsInProduction();
        $this->configureRateLimiting();
    }

    /**
     * A self-hosted server reached over a tunnel is on the public internet, so
     * generated URLs must not fall back to http:// once it's live. Local
     * development stays on whatever scheme it's already using.
     */
    private function enforceHttpsInProduction(): void
    {
        if ($this->app->environment('production')) {
            URL::forceScheme('https');
        }
    }

    /**
     * Remember when each scheduled task last ran, and how it went.
     *
     * Laravel keeps no history. On a self-hosted server the schedule is
     * otherwise invisible: a task that stopped running -- because the scheduler
     * process died, or its command began failing -- looks exactly like a task
     * with nothing to do. The dashboard reads this back.
     *
     * Registered for all three outcomes. Only recording success would leave a
     * failing task showing its last good run, which is the most misleading
     * thing the table could say.
     */
    /**
     * Free jobs a dead worker was holding, when a worker starts.
     *
     * A job is "reserved" while a worker has it, and the row carries no note of
     * which worker that was. Kill the worker mid-job — a crash, a restart, an
     * install — and the row stays reserved forever as far as anyone can tell.
     * The queue only reconsiders it after `retry_after`, which this app sets
     * above the worker's six-hour timeout because the alternative is failing
     * jobs that are still running. So a restart used to cost one job ninety
     * seconds and now costs it six hours, which is not a trade worth making
     * when the restart is the thing that proves nothing is in flight.
     *
     * Safe because the supervisor runs exactly one worker: when it is starting,
     * nothing can be holding anything. That assumption is the whole basis for
     * this, so it is a setting rather than a certainty — a deployment running
     * several workers must turn it off, or a worker starting will free a job
     * its sibling is part-way through.
     */
    private function releaseStrandedJobs(): void
    {
        Event::listen(WorkerStarting::class, function (WorkerStarting $event): void {
            if (! config('queue.release_reservations_on_worker_start')) {
                return;
            }

            // The connection the worker is actually starting on, not the
            // application default: they differ, and the default is the wrong
            // one to reason about when the event names the right one.
            $connection = (string) $event->connectionName;

            // Only a database queue keeps its reservations in a table we own.
            if (config("queue.connections.{$connection}.driver") !== 'database') {
                return;
            }

            try {
                $table = config("queue.connections.{$connection}.table", 'jobs');

                $freed = DB::table($table)->whereNotNull('reserved_at')->update(['reserved_at' => null]);

                if ($freed > 0) {
                    // Worth a line: these jobs are about to run a second time,
                    // and if one of them is what killed the worker, this is the
                    // record that it was handed back rather than given up on.
                    Log::info('Released jobs a stopped worker was holding', [
                        'jobs' => $freed,
                        'hint' => 'A worker was killed mid-job. Their attempt counts are unchanged, so a job that keeps failing still gives up.',
                    ]);
                }
            } catch (Throwable $e) {
                // Never stop a worker from starting over this.
                Log::warning('Could not release stranded jobs', [
                    'error' => $e->getMessage(),
                ]);
            }
        });
    }

    /**
     * Lets the panel pause background work without killing the worker.
     *
     * `queue:restart` would end the process, and on this install a LaunchAgent
     * starts it straight back up -- so that is a restart, not a pause.
     * Returning false from `looping` stops the worker *reserving* the next job
     * while leaving the process alive, which is what pause should mean.
     *
     * Nothing in flight is interrupted: a transcode half-way through finishes,
     * because this runs between jobs rather than during one.
     *
     * The flag is read fresh each time round the loop, so resuming takes effect
     * within one poll rather than needing a restart.
     */
    private function honourQueuePause(): void
    {
        // `isPaused()` swallows a cache failure itself and answers "not
        // paused", so a broken cache cannot wedge the queue from here either.
        Queue::looping(fn (): bool => ! app(QueueControl::class)->isPaused());
    }

    private function recordScheduledRuns(): void
    {
        $record = fn (string $outcome) => function ($event) use ($outcome): void {
            app(ScheduleInspector::class)->recordRun(
                $event->task,
                $outcome,
                $event->runtime ?? null,
            );
        };

        Event::listen(ScheduledTaskFinished::class, $record('ok'));
        Event::listen(ScheduledTaskFailed::class, $record('failed'));
        Event::listen(ScheduledTaskSkipped::class, $record('skipped'));
    }

    /**
     * Throttles the endpoints worth guessing at.
     *
     * Login is the one route where an attacker gets unlimited free attempts,
     * and a home server is reachable from anywhere once tunnelled — so it's
     * limited per IP *and* per account, since a botnet defeats IP limits alone
     * while a single attacker defeats account limits by spraying addresses.
     */
    private function configureRateLimiting(): void
    {
        RateLimiter::for('login', function (Request $request): array {
            $email = (string) $request->input('email');

            return [
                Limit::perMinute(5)->by('login-ip:'.$request->ip()),
                Limit::perMinute(5)->by('login-user:'.mb_strtolower($email)),
            ];
        });

        RateLimiter::for('register', fn (Request $request) => Limit::perHour(5)->by($request->ip()));

        // Streaming issues many range requests per track, so this is set high
        // enough to never bother a real listener while still capping a scraper
        // trying to pull the whole library.
        RateLimiter::for('stream', fn (Request $request) => Limit::perMinute(120)
            ->by($request->user()?->id ?: $request->ip()));

        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(60)
            ->by($request->user()?->id ?: $request->ip()));
    }
}
