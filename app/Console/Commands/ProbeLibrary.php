<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Console\Commands;

use App\Enums\MediaItemType;
use App\Models\MediaItem;
use App\Services\Quality\MediaProber;
use App\Services\Quality\QualityChecker;
use Illuminate\Console\Command;

/**
 * Measures files the pipeline has not reached (#467).
 *
 * The pipeline probes everything from now on, but a library catalogued before
 * #467 has no measurements at all -- which is the state where keep-best for
 * video compares file sizes and nothing knows a file is truncated.
 *
 * Local work only: reads the file, writes a row. No network, so no rate limit
 * and no reason to run it in slices unless the library is large enough that
 * reading every file takes a while.
 */
class ProbeLibrary extends Command
{
    protected $signature = 'library:probe
        {--limit=0 : Stop after this many items (0 = all)}
        {--type= : Only this media type (music, movie, show, book)}
        {--reprobe : Re-measure items that already have a probe}
        {--skip-checks : Measure without running the quality checks}';

    protected $description = 'Record what each file contains, and what is wrong with it';

    public function handle(MediaProber $prober, QualityChecker $checker): int
    {
        if (! $prober->isAvailable()) {
            // A clear refusal rather than a run that silently probes nothing.
            $this->error('ffprobe is not available, so nothing can be measured. Set FFPROBE_PATH or install it.');

            return self::FAILURE;
        }

        $query = MediaItem::withoutGlobalScopes()
            ->with(['musicMetadata', 'movieMetadata', 'probe'])
            ->orderBy('id');

        if (! $this->option('reprobe')) {
            $query->whereDoesntHave('probe');
        }

        if ($type = $this->option('type')) {
            $resolved = MediaItemType::tryFrom((string) $type);

            if ($resolved === null) {
                $this->error("Unknown type: {$type}.");

                return self::FAILURE;
            }

            $query->where('type', $resolved);
        }

        $limit = max(0, (int) $this->option('limit'));
        $total = $query->count();
        $take = $limit > 0 ? min($limit, $total) : $total;

        if ($take === 0) {
            $this->info('Nothing to measure.');

            return self::SUCCESS;
        }

        $this->info("Measuring {$take} item(s).");

        $probed = $missing = $unreadable = $skipped = 0;
        $problems = [];

        $bar = $this->output->createProgressBar($take);
        $bar->start();

        foreach ($query->limit($take)->get() as $item) {
            $path = $item->absoluteFilePath();

            if ($path === null || ! is_file($path)) {
                // Expected on this machine, where the library's files live
                // elsewhere. Counted rather than reported as a failure.
                $missing++;
                $bar->advance();

                continue;
            }

            if ($prober->probe($item) === null) {
                // A book carries no streams, so ffprobe refusing an EPUB is
                // the expected answer rather than a problem -- counted apart,
                // or a library of books reads as a library of broken files.
                $item->type === MediaItemType::Book ? $skipped++ : $unreadable++;

                $bar->advance();

                continue;
            }

            $probed++;

            if (! $this->option('skip-checks')) {
                foreach ($checker->check($item->fresh()) as $finding) {
                    if ($finding->isBlocking()) {
                        $problems[] = $item->id.': '.str_replace('_', ' ', (string) $finding->check);
                    }
                }
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        $this->info("Measured {$probed}.".
            ($missing > 0 ? " {$missing} had no file on this machine." : '').
            ($skipped > 0 ? " {$skipped} book(s) carry no streams to measure." : '').
            ($unreadable > 0 ? " {$unreadable} could not be read." : ''));

        if ($problems !== []) {
            $this->newLine();
            $this->warn(count($problems).' item(s) have a problem worth looking at:');

            foreach (array_slice($problems, 0, 20) as $problem) {
                $this->line('  '.$problem);
            }

            if (count($problems) > 20) {
                $this->line('  ... and '.(count($problems) - 20).' more. They are in the review queue.');
            }
        }

        return self::SUCCESS;
    }
}
