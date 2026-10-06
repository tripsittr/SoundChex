<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Console\Commands;

use App\Services\Pipeline\PipelineSweeper;
use Illuminate\Console\Command;

/**
 * Finds and restarts pipeline work that was lost (#489).
 *
 * Scheduled every five minutes. It is what makes "no item stays invisible and
 * idle" true rather than aspirational: a crash, a killed worker, a flushed
 * queue or a provider outage all leave items that nothing else would ever look
 * at again.
 */
class SweepPipeline extends Command
{
    protected $signature = 'library:pipeline-sweep {--quiet-ok : Print nothing when there was nothing to do}';

    protected $description = 'Requeue stalled, lost and retryable pipeline work';

    public function handle(PipelineSweeper $sweeper): int
    {
        $outcome = $sweeper->sweep();
        $total = array_sum($outcome);

        if ($total === 0) {
            // Scheduled every five minutes, so silence on an idle library is
            // the difference between a log worth reading and one nobody does.
            if (! $this->option('quiet-ok')) {
                $this->info('Nothing to sweep.');
            }

            return self::SUCCESS;
        }

        $this->table(
            ['what', 'count'],
            collect($outcome)->filter()->map(fn (int $n, string $k): array => [
                str_replace('_', ' ', $k),
                $n,
            ])->values()->all(),
        );

        $stranded = $sweeper->strandedCount();

        if ($stranded > 0) {
            // The one number that must be zero. Said loudly because an item
            // that is hidden and owed nothing is invisible to every other
            // report.
            $this->warn("{$stranded} item(s) are hidden from the library with no pipeline stage.");
        }

        return self::SUCCESS;
    }
}
