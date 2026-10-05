<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Services;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * What the scheduler is supposed to do, and when it last did it.
 *
 * The schedule is defined in `routes/console.php` and is otherwise invisible: on
 * a self-hosted server, the only way to know whether the nightly scan or the
 * hourly health check ever ran was to read the log. A task that silently stopped
 * — because the scheduler process died, or its command started failing — looked
 * exactly like a task with nothing to do.
 *
 * Laravel does not record run history, so `recordRun()` is called from an event
 * listener and the result cached. Cache rather than a table because this is
 * operational trivia with a short useful life: losing it on a cache clear costs
 * a row of dashes until the task next runs.
 */
class ScheduleInspector
{
    /** Long enough that a daily task still shows its last run. */
    private const TTL_DAYS = 8;

    /**
     * Every scheduled task, in the order the scheduler holds them.
     *
     * @return Collection<int, array{
     *     key: string,
     *     name: string,
     *     expression: string,
     *     frequency: string,
     *     next_run: Carbon|null,
     *     last_run: Carbon|null,
     *     last_outcome: string|null,
     *     last_duration_ms: int|null,
     *     overlapping: bool,
     *     background: bool,
     * }>
     */
    public function all(): Collection
    {
        return collect(app(Schedule::class)->events())
            ->map(function (Event $event): array {
                $run = $this->lastRun($this->keyFor($event));

                return [
                    'key' => $this->keyFor($event),
                    'name' => $this->nameFor($event),
                    'expression' => $event->expression,
                    'frequency' => $this->describe($event->expression),
                    'next_run' => $this->nextRun($event),
                    'last_run' => isset($run['at']) ? Carbon::createFromTimestamp($run['at']) : null,
                    'last_outcome' => $run['outcome'] ?? null,
                    'last_duration_ms' => $run['duration_ms'] ?? null,
                    'overlapping' => ! $event->withoutOverlapping,
                    'background' => $event->runInBackground,
                ];
            })
            ->values();
    }

    /**
     * Record that a task ran. Called from the scheduler's own events, which fire
     * in the `schedule:run` process rather than here.
     */
    public function recordRun(Event $event, string $outcome, ?float $runtimeSeconds = null): void
    {
        Cache::put(
            $this->cacheKey($this->keyFor($event)),
            [
                'at' => now()->timestamp,
                'outcome' => $outcome,
                'duration_ms' => $runtimeSeconds === null ? null : (int) round($runtimeSeconds * 1000),
            ],
            now()->addDays(self::TTL_DAYS),
        );
    }

    /**
     * A stable identity for a task.
     *
     * `mutexName()` hashes the command and is stable across restarts, which
     * neither the array index nor the description is — a task added to
     * `routes/console.php` would otherwise shift every row's history by one.
     */
    private function keyFor(Event $event): string
    {
        return $event->mutexName();
    }

    /**
     * What to call the task on screen.
     *
     * An explicit `->name()` wins, then the command, then the description. A
     * closure with neither is "Closure" — honest, and the reason the heartbeat
     * in `routes/console.php` names itself.
     */
    private function nameFor(Event $event): string
    {
        $summary = $event->getSummaryForDisplay();

        if ($summary !== 'Closure' && $summary !== '') {
            // The command line carries the full php binary path on a bundled
            // server, which is noise in a table.
            return (string) preg_replace('/^.*?artisan["\']?\s+/i', '', $summary);
        }

        return $event->description ?: 'Closure';
    }

    /** @return array{at?: int, outcome?: string, duration_ms?: int|null} */
    private function lastRun(string $key): array
    {
        $value = Cache::get($this->cacheKey($key));

        return is_array($value) ? $value : [];
    }

    private function cacheKey(string $key): string
    {
        return 'soundchex.schedule.last-run.'.$key;
    }

    private function nextRun(Event $event): ?Carbon
    {
        try {
            return Carbon::instance($event->nextRunDate());
        } catch (\Throwable) {
            // An expression Cron cannot project forward should not take the
            // whole table down with it.
            return null;
        }
    }

    /**
     * A cron expression in words, for the common shapes this app uses.
     *
     * Falls back to the expression itself, which is worse to read but never
     * wrong — guessing at an unusual expression would be.
     */
    private function describe(string $expression): string
    {
        return match (true) {
            $expression === '* * * * *' => 'Every minute',
            (bool) preg_match('~^\*/(\d+) \* \* \* \*$~', $expression, $m) => 'Every '.$m[1].' minutes',
            (bool) preg_match('~^(\d+) \* \* \* \*$~', $expression) => 'Hourly',
            (bool) preg_match('~^(\d+) (\d+) \* \* \*$~', $expression, $m) => 'Daily at '.sprintf('%02d:%02d', $m[2], $m[1]),
            (bool) preg_match('~^(\d+) (\d+) \* \* (\d)$~', $expression, $m) => 'Weekly',
            (bool) preg_match('~^(\d+) (\d+) 1 \* \*$~', $expression) => 'Monthly',
            default => $expression,
        };
    }
}
