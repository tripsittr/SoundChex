<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Console\Commands;

use App\Models\MediaItem;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Clears failed jobs that failed only because their item had been deleted.
 *
 * Those failures are not failures: a duplicate merged away while its enrich
 * job sat in the queue leaves a job with nothing to do, and the jobs now treat
 * that as a skip (#489). The rows already in `failed_jobs` predate that fix and
 * stay there forever, where they read as a broken pipeline — three on this Mac,
 * six on the Windows server.
 *
 * Deliberately narrow. It removes a row only when the exception is
 * `ModelNotFoundException`, the job is one of the two that had the bug, **and**
 * the item id in the payload still does not resolve. Anything else is a real
 * failure and is left alone: a failed job is evidence, and clearing evidence
 * because it is untidy is how a real problem gets lost.
 */
class PruneVanishedItemFailures extends Command
{
    protected $signature = 'queue:prune-vanished-failures
        {--dry-run : List what would be removed without removing it}';

    protected $description = 'Clear failed jobs whose media item no longer exists';

    /** The jobs that used findOrFail() and so failed on a deleted item. */
    private const AFFECTED = [
        'App\Jobs\EnrichMediaItemJob',
        'App\Jobs\TranscodeMediaJob',
    ];

    public function handle(): int
    {
        $rows = DB::table('failed_jobs')
            ->where('exception', 'like', 'Illuminate\Database\Eloquent\ModelNotFoundException%')
            ->get(['id', 'uuid', 'payload', 'failed_at']);

        $removable = [];

        foreach ($rows as $row) {
            $payload = json_decode((string) $row->payload, true);
            $job = $payload['displayName'] ?? null;

            if (! in_array($job, self::AFFECTED, true)) {
                continue;
            }

            $id = $this->mediaItemIdFrom($payload);

            // Still resolvable means something else went wrong -- a scope, a
            // relation -- and that is a real failure to keep.
            if ($id === null || MediaItem::withoutGlobalScopes()->whereKey($id)->exists()) {
                continue;
            }

            $removable[] = ['id' => $row->id, 'uuid' => $row->uuid, 'job' => $job, 'item' => $id, 'at' => $row->failed_at];
        }

        if ($removable === []) {
            $this->info('No failed jobs are waiting on a deleted item.');

            return self::SUCCESS;
        }

        $this->table(
            ['uuid', 'job', 'item', 'failed at'],
            array_map(fn (array $r): array => [
                substr((string) $r['uuid'], 0, 8),
                class_basename($r['job']),
                $r['item'],
                $r['at'],
            ], $removable),
        );

        if ($this->option('dry-run')) {
            $this->info(count($removable).' would be removed. Re-run without --dry-run to apply.');

            return self::SUCCESS;
        }

        DB::table('failed_jobs')->whereIn('id', array_column($removable, 'id'))->delete();

        $this->info('Removed '.count($removable).' failed job(s) whose item no longer exists.');

        return self::SUCCESS;
    }

    /**
     * The media item id a queued job carried.
     *
     * Both affected jobs take it as their first constructor argument, which
     * Laravel serialises into the command payload.
     */
    private function mediaItemIdFrom(array $payload): ?int
    {
        $command = $payload['data']['command'] ?? null;

        if (! is_string($command)) {
            return null;
        }

        // The serialised object carries the public property by name. Read it
        // rather than unserialising, which would need the class to still
        // accept the same shape.
        if (preg_match('/mediaItemId";i:(\d+);/', $command, $match) === 1) {
            return (int) $match[1];
        }

        return null;
    }
}
