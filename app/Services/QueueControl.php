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
            // The payload carries the fully-qualified name; the table shows the
            // short one.
            $query->where('payload', 'like', '%'.str_replace('\\', '\\\\', $job).'%');
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
