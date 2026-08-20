<?php

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
