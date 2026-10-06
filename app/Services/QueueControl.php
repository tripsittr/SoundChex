<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Starting, stopping and clearing background work from the panel.
 *
 * The dashboard could say 7,018 jobs were waiting and offer nothing to do about
 * it. Pausing meant killing the worker from a terminal — which on this install
 * means a LaunchAgent that respawns it — and clearing a stuck queue meant
 * `DELETE FROM jobs` by hand.
 *
 * ## Why a flag rather than stopping the worker
 *
 * `queue:restart` kills the process and the supervisor starts it again, so it
 * is a restart, not a pause. Instead the worker keeps running and simply
 * declines to take the next job: `Queue::looping()` returning false stops the
 * loop reserving work without ending the process, which is what "pause" should
 * mean. Nothing in flight is interrupted — a transcode half-way through
 * finishes.
 *
 * The flag lives in the cache rather than a file so it works the same on the
 * bundled server, a Docker install and a dev machine, and so a worker on
 * another host reads the same answer.
 */
class QueueControl
{
    /** Set while work is paused. */
    private const PAUSED_KEY = 'soundchex.queue.paused';

    /**
     * Long enough that a forgotten pause is obvious, short enough that a
     * crashed panel cannot wedge the queue forever. A day of no enrichment is
     * noticeable; a week of it looks like the app is broken.
     */
    private const PAUSE_TTL_HOURS = 24;

    /** Which job kinds are paused, as a list of short class names. */
    private const PAUSED_JOBS_KEY = 'soundchex.queue.paused_jobs';

    /** How many jobs may run at once; one worker per job. */
    private const CONCURRENCY_KEY = 'queue.concurrency';

    /**
     * Beyond this the disk is the bottleneck rather than the queue, and an
     * accidental 64 would make the machine unusable rather than fast.
     */
    public const MAX_CONCURRENCY = 8;

    /**
     * Whether work is paused.
     *
     * Guarded here rather than only at the call site: a cache that cannot be
     * read must not wedge the queue, and the test for that found the try/catch
     * sitting in the provider hook where every *other* caller was unprotected.
     *
     * The safe direction is to keep working. A stuck pause looks like the app
     * is broken; an ignored one is merely annoying.
     */
    /**
     * How many jobs may run at once.
     *
     * **One by default**, deliberately: enrichment is rate-limited by the
     * services it calls -- MusicBrainz allows one request a second -- so a
     * second job in parallel buys nothing there and doubles the chance of
     * tripping a limit. Hashing and transcoding are the exceptions, bound by
     * this machine rather than somebody else's, which is why the number is a
     * setting rather than a constant.
     *
     * Implemented as the number of workers, because one worker takes one job
     * at a time: that is what concurrency means for a queue.
     *
     * Capped at eight. Beyond that the disk is the bottleneck rather than the
     * queue, and an accidental 64 would make the machine unusable rather than
     * fast.
     */
    public function concurrency(): int
    {
        $count = (int) app(SettingsService::class)->get(self::CONCURRENCY_KEY, 1);

        return max(1, min($count, self::MAX_CONCURRENCY));
    }

    public function setConcurrency(int $count): void
    {
        $count = max(1, min($count, self::MAX_CONCURRENCY));

        app(SettingsService::class)->set(self::CONCURRENCY_KEY, $count);

        Log::info('Job concurrency changed from the panel', ['jobs_at_once' => $count]);
    }

    /**
     * Whether a particular kind of job is paused.
     *
     * Per job rather than all-or-nothing, because the reason to pause is
     * almost always specific: enrichment is hammering a rate-limited API, or a
     * transcode is making the machine unusable. Stopping everything to deal
     * with one of them also stops the cover fetches and the duplicate scan,
     * which were not the problem.
     */
    public function isJobPaused(string $job): bool
    {
        return in_array($job, $this->pausedJobs(), true);
    }

    /**
     * Every job kind currently paused.
     *
     * @return array<int, string>
     */
    public function pausedJobs(): array
    {
        try {
            $jobs = Cache::get(self::PAUSED_JOBS_KEY, []);

            return is_array($jobs) ? array_values(array_filter($jobs, 'is_string')) : [];
        } catch (\Throwable $e) {
            Log::warning('Could not read the per-job pause list; treating all as running', [
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    public function pauseJob(string $job): void
    {
        $jobs = array_values(array_unique([...$this->pausedJobs(), $job]));

        $until = now()->addHours(self::PAUSE_TTL_HOURS);

        Cache::put(self::PAUSED_JOBS_KEY, $jobs, $until);

        Log::info('A job kind was paused from the panel', ['job' => $job, 'until' => $until->toIso8601String()]);
    }

    public function resumeJob(string $job): void
    {
        $jobs = array_values(array_filter($this->pausedJobs(), fn (string $k): bool => $k !== $job));

        if ($jobs === []) {
            Cache::forget(self::PAUSED_JOBS_KEY);
        } else {
            Cache::put(self::PAUSED_JOBS_KEY, $jobs, now()->addHours(self::PAUSE_TTL_HOURS));
        }

        Log::info('A job kind was resumed from the panel', ['job' => $job]);
    }

    public function isPaused(): bool
    {
        try {
            return (bool) Cache::get(self::PAUSED_KEY, false);
        } catch (\Throwable $e) {
            Log::warning('Could not read the queue pause flag; treating work as running', [
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /** When the pause lapses on its own, if one is in effect. */
    public function pausedUntil(): ?\DateTimeInterface
    {
        $at = Cache::get(self::PAUSED_KEY.'.until');

        return is_numeric($at) ? CarbonImmutable::createFromTimestamp((int) $at) : null;
    }

    public function pause(): void
    {
        $until = now()->addHours(self::PAUSE_TTL_HOURS);

        Cache::put(self::PAUSED_KEY, true, $until);
        Cache::put(self::PAUSED_KEY.'.until', $until->timestamp, $until);

        Log::info('Background work paused from the panel', ['until' => $until->toIso8601String()]);
    }

    public function resume(): void
    {
        Cache::forget(self::PAUSED_KEY);
        Cache::forget(self::PAUSED_KEY.'.until');

        Log::info('Background work resumed from the panel');
    }

    /**
     * Throws away queued work of one kind, or all of it.
     *
     * Jobs a worker has in hand are left alone: `reserved_at` means the bytes
     * may already be moving, and deleting the row would not stop that -- it
     * would only lose the record of what was running.
     *
     * Returns how many were discarded, which is the number worth reporting.
     */
    public function cancel(?string $job = null): int
    {
        $query = DB::table('jobs')->whereNull('reserved_at');

        if ($job !== null) {
            // Two separate transformations, and conflating them was the bug.
            //
            // First the name is written the way JSON stores it: the payload is
            // a JSON document, so a class name's backslashes appear doubled in
            // it and a raw PHP name matches nothing.
            //
            // Then LIKE's own metacharacters are escaped, with `ESCAPE` spelled
            // out -- a5's review caught that `%` and `_` went through raw, and
            // without the clause the escaping is merely characters: a bare `%`
            // matched every payload and would have emptied the queue. The name
            // comes from a fixed set today, but a filter that is safe only
            // because of where its input happens to come from stops being safe
            // the moment somebody points a search box at it.
            $pattern = $this->escapeLike(str_replace('\\', '\\\\', $job));

            $query->whereRaw("payload LIKE ? ESCAPE '~'", ['%'.$pattern.'%']);
        }

        $count = $query->count();

        if ($count === 0) {
            return 0;
        }

        $query->delete();

        Log::warning('Queued work discarded from the panel', [
            'job' => $job ?? 'all',
            'discarded' => $count,
        ]);

        return $count;
    }

    /**
     * Escapes a string for a LIKE pattern.
     *
     * Backslash first, or it would double-escape the escapes added after it.
     * `%` matches anything and `_` matches one character, so an unescaped one
     * in a job name would widen the match silently rather than fail loudly --
     * and this method deletes rows.
     */
    private function escapeLike(string $value): string
    {
        // `~` as the escape character rather than a backslash, because the
        // value legitimately *contains* backslashes -- a JSON-escaped class
        // name is mostly backslashes -- and using one as the escape would mean
        // escaping every one of them again. `~` appears in no PHP class name.
        return str_replace(['~', '%', '_'], ['~~', '~%', '~_'], $value);
    }

    /**
     * Clears the failed-job record.
     *
     * The rows are a log, not work: nothing retries from them unless somebody
     * asks. Clearing loses the reasons, which is why the panel says how many
     * and what they were before offering it.
     */
    public function clearFailed(): int
    {
        $count = DB::table('failed_jobs')->count();

        if ($count === 0) {
            return 0;
        }

        DB::table('failed_jobs')->delete();

        Log::info('Failed-job records cleared from the panel', ['cleared' => $count]);

        return $count;
    }
}
