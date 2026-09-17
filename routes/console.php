<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

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
    $interval = app(App\Services\LibrarySettings::class)->scanIntervalMinutes();
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
| Notifications are "what happened while you were away", not a permanent log.
| A device that has not opened in a month does not want a month of history, and
| an unbounded table on a server that scans every few minutes grows forever.
*/
Schedule::call(fn () => \App\Models\Notification::prune())
    ->daily()
    ->name('prune-notifications')
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

/*
| Device reports are for diagnosing something happening now. A fortnight-old
| report from a build that no longer exists is noise, and an unbounded table fed
| by every device grows without limit.
*/
Schedule::call(fn () => \App\Models\DeviceReport::prune())
    ->daily()
    ->name('prune-device-reports')
    ->withoutOverlapping();
