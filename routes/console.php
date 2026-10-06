<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

use App\Models\DeviceReport;
use App\Models\Notification;
use App\Services\LibrarySettings;
use App\Services\Streaming\HlsSegmenter;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Scheduled Tasks
|--------------------------------------------------------------------------
|
| Run with `php artisan schedule:work` (or a cron entry calling
| `schedule:run` every minute) so watched folders are picked up automatically.
|
*/

// Read through LibrarySettings so the interval set in the admin panel is the
// one that actually runs. Guarded because this file is also loaded by
// `package:discover` and `migrate` before the settings table exists.
try {
    $interval = app(LibrarySettings::class)->scanIntervalMinutes();
} catch (Throwable) {
    $interval = max(1, (int) config('library.scan_interval_minutes', 5));
}

Schedule::command('library:scan')
    ->cron("*/{$interval} * * * *")
    // A slow scan of a large folder must not stack up behind itself.
    ->withoutOverlapping()
    // Nothing to see when there are no new files; only failures are worth
    // surfacing in the log.
    ->runInBackground();

/*
| A nightly snapshot of the catalogue.
|
| The media is safe on disk regardless — the database is only a catalogue — but
| play history, watchlists, ratings, playlists and profiles exist nowhere else
| and cannot be rebuilt by rescanning. Compressed this costs a few hundred
| kilobytes a night, which is nothing against having to rebuild by hand.
*/
Schedule::command('db:backup')
    ->dailyAt('04:00')
    // A backup that stacks up behind a slow one would compete for the same
    // database it is trying to snapshot.
    ->withoutOverlapping()
    ->runInBackground();

/*
| Empty the media trash past its retention window.
|
| Deletes in the library are moves to `media/.trash` rather than unlinks, so a
| wrong merge stays recoverable (#464) -- but something has to remove them
| eventually. Retention is `library.trash_days`, 30 by default; zero disables
| this entirely and the command then does nothing.
|
| After the backup, so a night's run never competes with it for disk.
*/
Schedule::command('library:purge-trash')
    ->dailyAt('04:30')
    ->withoutOverlapping()
    ->runInBackground();

/*
| Find pipeline work that was lost and make it happen (#465).
|
| A crash, a killed worker, a flushed queue or a provider outage all leave
| items that nothing else would ever look at again -- hidden from the library
| because they are not `complete`, and absent from review because nothing
| flagged them. This is what makes "no item stays invisible and idle" true.
|
| Five minutes is short enough that a lost import is noticed while the user is
| still watching, and long enough that a stage with a sixty-second timeout is
| not requeued while it is legitimately working.
*/
Schedule::command('library:pipeline-sweep --quiet-ok')
    ->everyFiveMinutes()
    // A slow sweep over a large library must not stack up behind itself.
    ->withoutOverlapping()
    ->runInBackground();

/*
| The same invariant, from the other end (#510).
|
| `server:health` asserts hourly that no item is hidden from the library
| without an open review item saying why -- and nothing maintained that. The
| backfill is what restores it, and it ran only when somebody typed it.
|
| Observed over one session as enrichment produced fuzzy matches: the count
| went 8, then 94, then 377, then 611, with health reporting "unhealthy" each
| hour and the remedy sitting behind a command the owner had no reason to know
| existed. A dashboard that reports a problem nobody can clear teaches people
| to ignore the dashboard.
|
| Alongside the sweep rather than on its own timer: they fix the two halves of
| the same guarantee, and an item the sweep has just parked is exactly the one
| this needs to explain.
*/
Schedule::command('library:backfill-review --quiet-ok')
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->runInBackground();

/*
| Notifications are "what happened while you were away", not a permanent log.
| A device that has not opened in a month does not want a month of history, and
| an unbounded table on a server that scans every few minutes grows forever.
*/
Schedule::call(fn () => Notification::prune())
    ->daily()
    ->name('prune-notifications')
    ->withoutOverlapping();

/*
| HLS segments are written for one viewing and never read again once it ends.
| A film is gigabytes of them, so without a sweep they accumulate until the
| disk fills (S-29). Two hours is comfortably longer than any film, and age is
| measured from the newest segment so a long one is not swept mid-playback.
*/
Schedule::call(fn () => app(HlsSegmenter::class)->sweep())
    ->hourly()
    ->name('sweep-hls-sessions')
    ->withoutOverlapping();

/*
| Proof the scheduler is alive.
|
| Everything else here is periodic work; this is the scheduler saying it ran.
| Without it the host application cannot tell a scheduler that is running from
| one that died hours ago — both look like "no tasks due right now".
*/
/*
 * How reachable this server is, measured out of band.
 *
 * Never from a web request: `artisan serve` is single-threaded, so probing its
 * own addresses mid-request blocks on the process that would answer and times
 * out against itself. The dashboard reads the result rather than taking it.
 */
Schedule::command('network:probe')
    ->everyFiveMinutes()
    ->withoutOverlapping();

/*
 * Keeps APP_URL pointed at a real address of this machine.
 *
 * Only when it is unset or the install default — a deliberately-set address (a
 * public tunnel, a reverse proxy) is left alone, since detection cannot know
 * about those. This is what makes the downloaded desktop app zero-config and
 * stops the "server moved networks, the phone can't find it" recurrence without
 * anyone editing .env. Hourly is plenty; an address does not change often.
 */
Schedule::command('server:detect-address')
    ->hourly()
    ->withoutOverlapping();

Schedule::call(fn () => cache()->put('soundchex.scheduler.heartbeat', now()->timestamp, now()->addMinutes(10)))
    ->everyMinute()
    ->name('scheduler-heartbeat');

// A periodic health check that emits the server.health event and records a
// notification when something is wrong (S-276). Quiet when healthy.
Schedule::command('server:health --quiet-ok')
    ->hourly()
    ->withoutOverlapping();

/*
| A daily assertion that the PHP runtime still has every required extension. The
| runtime does not change between deploys, so daily is plenty; the value is that
| a swapped or mis-built PHP (a self-hoster's host upgrade, a bad bundle) shows
| up in the log as a named missing extension rather than as a feature that
| quietly stopped working. --quiet-ok keeps the healthy case silent.
*/
Schedule::command('server:check-extensions --quiet-ok')
    ->daily()
    ->withoutOverlapping();

/*
| Device reports are for diagnosing something happening now. A fortnight-old
| report from a build that no longer exists is noise, and an unbounded table fed
| by every device grows without limit.
*/
Schedule::call(fn () => DeviceReport::prune())
    ->daily()
    ->name('prune-device-reports')
    ->withoutOverlapping();
