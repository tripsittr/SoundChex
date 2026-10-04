<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * What the queue is actually working on.
 *
 * Enrichment, cover fetching, duplicate hashing and transcoding all happen out
 * of sight. Starting any of them from the panel gave a notification and then
 * nothing: no way to tell whether 6,000 jobs were moving, stuck, or had died
 * with the worker. The only signals were the `jobs` table and the log.
 *
 * Read straight from the queue tables rather than tracked separately. They are
 * the truth — anything maintained alongside them would be a second account of
 * the same thing, free to disagree.
 */
class QueueInspector
{
    /**
     * Pending work, grouped by job, busiest first.
     *
     * @return Collection<int, array{
     *     job: string,
     *     queued: int,
     *     running: int,
     *     oldest: Carbon|null,
     *     attempted: int,
     * }>
     */
    public function pending(): Collection
    {
        return collect(DB::table('jobs')->get())
            ->groupBy(fn (object $row): string => $this->jobName($row->payload))
            ->map(fn (Collection $rows, string $job): array => [
                'job' => $job,
                'queued' => $rows->count(),
                // Reserved means a worker has it in hand right now.
                'running' => $rows->whereNotNull('reserved_at')->count(),
                'oldest' => ($at = $rows->min('created_at')) ? Carbon::createFromTimestamp($at) : null,
                // A job that has been attempted and is back in the queue failed
                // at least once; with tries=1 it will not be retried.
                'attempted' => $rows->where('attempts', '>', 0)->count(),
            ])
            ->sortByDesc('queued')
            ->values();
    }

    /**
     * Jobs that gave up, grouped by job and reason.
     *
     * @return Collection<int, array{job: string, reason: string, count: int, last: Carbon|null}>
     */
    public function failed(): Collection
    {
        return collect(DB::table('failed_jobs')->get())
            ->groupBy(fn (object $row): string => $this->jobName($row->payload).'|'.$this->reason($row->exception))
            ->map(function (Collection $rows): array {
                $first = $rows->first();

                return [
                    'job' => $this->jobName($first->payload),
                    'reason' => $this->reason($first->exception),
                    'count' => $rows->count(),
                    'last' => ($at = $rows->max('failed_at')) ? Carbon::parse($at) : null,
                ];
            })
            ->sortByDesc('count')
            ->values();
    }

    public function totalPending(): int
    {
        return DB::table('jobs')->count();
    }

    public function totalFailed(): int
    {
        return DB::table('failed_jobs')->count();
    }

    /**
     * The job's own name, without its namespace.
     *
     * `displayName` is what Laravel records for the queue; falling back to the
     * command class covers a job pushed in a shape that lacks it.
     */
    private function jobName(string $payload): string
    {
        $decoded = json_decode($payload, true);

        $name = is_array($decoded)
            ? ($decoded['displayName'] ?? $decoded['data']['commandName'] ?? 'Unknown job')
            : 'Unknown job';

        return class_basename((string) $name);
    }

    /**
     * The first line of the exception, trimmed to something a table can hold.
     *
     * Grouping on it is the point: twenty copies of "database is locked" is one
     * problem, not twenty, and a list of twenty identical rows hides whatever
     * else failed once.
     */
    private function reason(?string $exception): string
    {
        $first = strtok((string) $exception, "\n") ?: 'Unknown failure';

        // Drop the file and line, which differ between otherwise identical
        // failures and would split the grouping.
        $first = (string) preg_replace('~\s+in [A-Za-z]:[\\\\/].*$~', '', $first);

        return mb_strimwidth(trim($first), 0, 120, '…');
    }
}
