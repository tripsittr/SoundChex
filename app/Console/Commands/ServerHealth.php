<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Console\Commands;

use App\Events\ServerExtensionMissing;
use App\Events\ServerHealthChecked;
use App\Models\Notification;
use App\Services\Pipeline\PipelineSweeper;
use App\Services\Review\ReviewLog;
use App\Services\RuntimeHealth;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * A scheduled server health check that emits an observable event (S-276).
 *
 * There was no periodic health signal — only an on-demand read endpoint. This
 * gathers the same metrics on a schedule (disk free, queue backlog, failed jobs,
 * missing PHP extensions) and fires `server.health` so a monitoring plugin can
 * react — and records an in-app notification when something is actually wrong,
 * so a plain install still learns about it.
 */
class ServerHealth extends Command
{
    protected $signature = 'server:health {--quiet-ok : Say nothing when healthy}';

    protected $description = 'Check server health and emit the server.health event';

    /** Below this fraction of disk free, the server is considered unhealthy. */
    private const LOW_DISK_FRACTION = 0.05;

    /** More queued jobs than this is a backlog worth surfacing. */
    private const QUEUE_BACKLOG = 500;

    public function handle(RuntimeHealth $runtime): int
    {
        $missing = $runtime->missingRequired();

        if ($missing !== []) {
            ServerExtensionMissing::dispatch($missing);
        }

        $diskFree = $this->diskFreeFraction();
        $queueDepth = $this->tableCount('jobs');
        $failedJobs = $this->tableCount('failed_jobs');

        // The number that must be zero (#489): an item hidden from the library
        // with no pipeline stage is invisible to every other report -- absent
        // from the library because it is not `complete`, and absent from review
        // because nothing flagged it. Checked here so the guarantee is verified
        // continuously rather than believed.
        $stranded = app(PipelineSweeper::class)->strandedCount();

        // The other number that must be zero (#489): an item hidden from the
        // library with nothing open to explain it is absent from the library
        // AND absent from review at once, which is invisible to every other
        // report. Measured at 96 before the backfill; asserted here so the
        // guarantee is checked continuously rather than believed.
        $unexplained = app(ReviewLog::class)->hiddenWithNothingOpen();
        $stuckMoves = $this->tableCount('file_moves', fn ($query) => $query->where('state', 'started'));

        $problems = [];

        if ($missing !== []) {
            $problems[] = 'missing PHP extensions: '.implode(', ', $missing);
        }
        if ($diskFree !== null && $diskFree < self::LOW_DISK_FRACTION) {
            $problems[] = sprintf('low disk space (%d%% free)', (int) round($diskFree * 100));
        }
        if ($queueDepth > self::QUEUE_BACKLOG) {
            $problems[] = "queue backlog ({$queueDepth} jobs)";
        }
        if ($failedJobs > 0) {
            $problems[] = "{$failedJobs} failed job(s)";
        }
        if ($stranded > 0) {
            $problems[] = "{$stranded} item(s) hidden with no pipeline stage";
        }
        if ($unexplained > 0) {
            $problems[] = "{$unexplained} hidden item(s) with nothing saying why";
        }
        if ($stuckMoves > 0) {
            // A move left mid-flight. The sweeper reconciles these, so one
            // still sitting here means it could not decide -- both paths hold
            // different files and a person has to look.
            $problems[] = "{$stuckMoves} unfinished file move(s)";
        }

        $metrics = [
            'disk_free_fraction' => $diskFree,
            'queue_depth' => $queueDepth,
            'failed_jobs' => $failedJobs,
            'stranded_items' => $stranded,
            'unexplained_hidden_items' => $unexplained,
            'unfinished_moves' => $stuckMoves,
            'missing_extensions' => $missing,
        ];

        $healthy = $problems === [];

        ServerHealthChecked::dispatch($metrics, $healthy);

        if (! $healthy) {
            // record() fans out to webhooks and plugin notification targets, and
            // itself fires notification.recorded — so one unhealthy check reaches
            // every notifier.
            Notification::record('server_health', 'Server health check', implode('; ', $problems));

            $this->warn('Unhealthy: '.implode('; ', $problems));

            return self::SUCCESS;
        }

        if (! $this->option('quiet-ok')) {
            $this->info('Server is healthy.');
        }

        return self::SUCCESS;
    }

    /** Free disk as a fraction of total, or null if it cannot be read. */
    private function diskFreeFraction(): ?float
    {
        $path = storage_path();
        $free = @disk_free_space($path);
        $total = @disk_total_space($path);

        return ($free !== false && $total !== false && $total > 0) ? $free / $total : null;
    }

    private function tableCount(string $table, ?callable $filter = null): int
    {
        try {
            $query = DB::table($table);

            if ($filter !== null) {
                $filter($query);
            }

            return (int) $query->count();
        } catch (\Throwable) {
            // A table that does not exist yet -- a fresh install mid-migration
            // -- is not a health problem worth reporting as one.
            return 0;
        }
    }
}
