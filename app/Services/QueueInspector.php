<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Services;

use App\Models\MediaItem;
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
        // Counted in SQL, not in PHP. This read every row's payload to group
        // them — 2.6 MB at five thousand jobs — and the widget polls it every
        // ten seconds. sqlite can extract the name and count the groups itself.
        //
        // json_extract is sqlite and MySQL; Postgres spells it differently. The
        // bundled server is always sqlite, but config/database.php offers both
        // others, and a silently broken dashboard on one of them would be worse
        // than reading a few megabytes.
        if (! in_array(DB::connection()->getDriverName(), ['sqlite', 'mysql', 'mariadb'], true)) {
            return $this->pendingInPhp();
        }

        return collect(
            DB::table('jobs')
                ->selectRaw("json_extract(payload, '$.displayName') as display_name")
                ->selectRaw('count(*) as queued')
                // Reserved means a worker has it in hand right now.
                ->selectRaw('sum(case when reserved_at is not null then 1 else 0 end) as running')
                ->selectRaw('min(created_at) as oldest')
                // A job that has been attempted and is back in the queue failed
                // at least once; with tries=1 it will not be retried.
                ->selectRaw('sum(case when attempts > 0 then 1 else 0 end) as attempted')
                ->groupBy('display_name')
                ->get()
        )
            ->map(fn (object $row): array => [
                'job' => class_basename((string) ($row->display_name ?? 'Unknown job')),
                'queued' => (int) $row->queued,
                'running' => (int) $row->running,
                'oldest' => $row->oldest ? Carbon::createFromTimestamp($row->oldest) : null,
                'attempted' => (int) $row->attempted,
            ])
            ->sortByDesc('queued')
            ->values();
    }

    /**
     * The same grouping done in PHP, for a driver without json_extract.
     *
     * Loads every payload, which is what the SQL path exists to avoid; it is
     * here so an unusual database is slow rather than broken.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function pendingInPhp(): Collection
    {
        return collect(DB::table('jobs')->get())
            ->groupBy(fn (object $row): string => $this->jobName($row->payload))
            ->map(fn (Collection $rows, string $job): array => [
                'job' => $job,
                'queued' => $rows->count(),
                'running' => $rows->whereNotNull('reserved_at')->count(),
                'oldest' => ($at = $rows->min('created_at')) ? Carbon::createFromTimestamp($at) : null,
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
    /**
     * Everything known about one kind of job, for the modal behind its row.
     *
     * The table says six thousand jobs are waiting; it cannot say which file
     * the worker has open right now, nor why the last hundred came back needing
     * review. This answers both, and is only ever built for one job kind when
     * someone asks — the table itself must stay cheap enough to poll.
     *
     * @return array{
     *     job: string,
     *     running: list<array{id: int, target: string, item: int|null, since: Carbon|null, attempts: int}>,
     *     upcoming: list<array{id: int, target: string, item: int|null, waiting_since: Carbon|null}>,
     *     failures: Collection<int, array{job: string, reason: string, count: int, last: Carbon|null}>,
     *     log: Collection<int, array<string, mixed>>,
     *     has_log: bool,
     * }
     */
    public function detail(string $job, int $upcoming = 8, int $logLines = 40): array
    {
        $rows = DB::table('jobs')
            ->orderByRaw('reserved_at is null')
            ->orderBy('id')
            ->limit($upcoming + 10)
            ->get()
            ->filter(fn (object $row): bool => $this->jobName($row->payload) === $job);

        $running = $rows->whereNotNull('reserved_at')->values();
        $waiting = $rows->whereNull('reserved_at')->take($upcoming)->values();

        // One lookup for every item mentioned, rather than one per row.
        $titles = $this->titlesFor(
            $rows->map(fn (object $row): ?int => $this->itemId($row->payload))->filter()->unique()->values()->all()
        );

        $describe = fn (object $row): array => [
            'id' => (int) $row->id,
            'item' => $this->itemId($row->payload),
            'target' => $this->describe($row->payload, $titles),
        ];

        return [
            'job' => $job,
            'running' => $running->map(fn (object $row): array => $describe($row) + [
                'since' => $row->reserved_at ? Carbon::createFromTimestamp($row->reserved_at) : null,
                'attempts' => (int) $row->attempts,
            ])->all(),
            'upcoming' => $waiting->map(fn (object $row): array => $describe($row) + [
                'waiting_since' => $row->created_at ? Carbon::createFromTimestamp($row->created_at) : null,
            ])->all(),
            'failures' => $this->failed()->where('job', $job)->values(),
            // Filtered to the items this job kind is actually touching, so the
            // scheduler's and the web process's own entries do not crowd out
            // the ones that explain this job.
            'log' => app(WorkerLog::class)->recent(
                $logLines,
                $running->merge($waiting)->map(fn (object $row): ?int => $this->itemId($row->payload))->filter()->all()
            ),
            'has_log' => app(WorkerLog::class)->exists(),
        ];
    }

    /**
     * The media item a job is about, when it is about one.
     *
     * Read out of the serialised command with a pattern rather than by
     * unserialising it. Unserialising builds the job object, and a job that
     * uses SerializesModels will reach for the database to do it — which is
     * not something a page render should be made to do once per row.
     */
    private function itemId(string $payload): ?int
    {
        $decoded = json_decode($payload, true);
        $command = $decoded['data']['command'] ?? null;

        if (! is_string($command)) {
            return null;
        }

        return preg_match('~"(?:mediaItemId|itemId|id)";i:(\d+);~', $command, $m) === 1
            ? (int) $m[1]
            : null;
    }

    /** @param array<int, string> $titles */
    private function describe(string $payload, array $titles): string
    {
        $id = $this->itemId($payload);

        if ($id === null) {
            // A sweep rather than a single item. The scoped type, if it has one,
            // is the only thing that distinguishes two of them.
            $decoded = json_decode($payload, true);
            $command = $decoded['data']['command'] ?? '';

            return preg_match('~"type";s:\d+:"(\w+)";~', (string) $command, $m) === 1
                ? ucfirst($m[1]).' library'
                : 'Whole library';
        }

        return $titles[$id] ?? "Item {$id} (no longer in the library)";
    }

    /**
     * @param  list<int>  $ids
     * @return array<int, string>
     */
    private function titlesFor(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return MediaItem::withoutGlobalScopes()
            ->whereIn('id', $ids)
            ->get(['id', 'title', 'file_path'])
            ->mapWithKeys(fn (MediaItem $item): array => [
                $item->id => filled($item->title)
                    ? (string) $item->title
                    : basename((string) $item->file_path),
            ])
            ->all();
    }

    private function reason(?string $exception): string
    {
        $first = strtok((string) $exception, "\n") ?: 'Unknown failure';

        // Drop the file and line, which differ between otherwise identical
        // failures and would split the grouping.
        $first = (string) preg_replace('~\s+in [A-Za-z]:[\\\\/].*$~', '', $first);

        return mb_strimwidth(trim($first), 0, 120, '…');
    }
}
