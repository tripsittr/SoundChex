<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Providers;

use App\Events\PlaybackCompleted;
use App\Filesystem\WindowsSafeFilesystem;
use App\Services\CurrentProfile;
use App\Services\QueueControl;
use App\Services\QueueInspector;
use App\Services\ResumeFrames;
use App\Services\ScheduleInspector;
use Composer\CaBundle\CaBundle;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskSkipped;
use Illuminate\Http\Request;
use Illuminate\Queue\Events\JobProcessing;
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
     * How long a paused job waits before being offered again.
     *
     * Long enough that a paused kind is not re-reserved every few seconds for
     * hours, short enough that resuming is felt quickly.
     */
    private const PAUSED_JOB_RETRY_SECONDS = 60;

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

        // One per request, for the same reason and with a sharper edge: the
        // inspector memoises the throughput so that asking twice in one render
        // does not write a second sample and compare against a zero-second-old
        // one. Without this binding every `app()` call built a fresh instance
        // with an empty memo, so the widget's `throughput()` and
        // `minutesRemaining()` were separate measurements -- and the second
        // always returned null.
        //
        // The visible effect was a dashboard that said "about 47 a minute" and
        // never said how long that would take.
        $this->app->singleton(QueueInspector::class);

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
        $this->discardFinishedResumeFrames();
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

        // Per-job pause, which `looping` cannot express: that hook runs before
        // a job is reserved and so has no idea which one is next. `before` has
        // the job in hand, so a paused kind is released back to the queue --
        // with a delay, or the worker would spin on it -- while every other
        // kind keeps running.
        //
        // Releasing rather than deleting: a pause is "not now", and the work
        // is still wanted. The job rejoins the queue and runs when the kind is
        // resumed.
        Queue::before(function (JobProcessing $event): void {
            $name = class_basename((string) ($event->job->payload()['displayName'] ?? ''));

            if ($name === '' || ! app(QueueControl::class)->isJobPaused($name)) {
                return;
            }

            // Far enough out that a paused kind is not re-reserved every few
            // seconds for hours, close enough that resuming is felt quickly.
            $event->job->release(self::PAUSED_JOB_RETRY_SECONDS);
        });
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

    /**
     * Drops an item's resume frames once a profile finishes it (#513).
     *
     * Nothing else reclaims them. A frame is written per item per ten seconds
     * watched, so a server left alone accumulates them for everything anyone
     * ever started, and the moment it is certainly safe to delete them is when
     * the shelf stops offering the item back.
     *
     * Scoped to the profile in the event: other people sharing the server may
     * be part-way through the same episode, and their frames have to survive
     * someone else reaching the end.
     *
     * Failures are swallowed deliberately. This is housekeeping behind a
     * progress write the player is waiting on, and a full disk or a vanished
     * directory must not turn "you finished the film" into an error.
     */
    private function discardFinishedResumeFrames(): void
    {
        Event::listen(PlaybackCompleted::class, function (PlaybackCompleted $event): void {
            try {
                app(ResumeFrames::class)->forget($event->item, $event->profileId);
            } catch (Throwable $exception) {
                Log::warning('Could not discard resume frames for a finished item', [
                    'item' => $event->item->id,
                    'profile' => $event->profileId,
                    'error' => $exception->getMessage(),
                ]);
            }
        });
    }
}
