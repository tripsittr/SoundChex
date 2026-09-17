<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Console\Commands;

use App\Services\LibraryScanner;
use Illuminate\Console\Command;

/**
 * Scans the watched folders for files that aren't in the library yet.
 *
 * Runs on a schedule, so dropping a file into a watched folder is all it takes
 * to get it catalogued and enriched. The same scan is available on demand from
 * the admin panel.
 */
class ScanLibrary extends Command
{
    protected $signature = 'library:scan
        {--path=* : Scan these folders instead of the configured watch list}
        {--dry-run : List what would be imported without writing anything}
        {--no-enrich : Skip queueing metadata enrichment}';

    protected $description = 'Detect and catalog new audio files in the watched folders';

    public function handle(LibraryScanner $scanner): int
    {
        $folders = $this->option('path') ?: null;

        $result = $scanner->scan(
            folders: $folders,
            dryRun: (bool) $this->option('dry-run'),
            enrich: ! $this->option('no-enrich'),
        );

        if ($result['folders'] === 0) {
            $this->warn('No watch folders configured.');
            $this->line('Set LIBRARY_WATCH_FOLDERS in .env, or pass --path=/some/folder');

            return self::SUCCESS;
        }

        foreach ($result['titles'] as $title) {
            $this->line('  + ' . $title);
        }

        $this->reportOutcome($result);

        return self::SUCCESS;
    }

    /**
     * @param array{imported: int, unsettled: int, folders: int, titles: array<int, string>} $result
     */
    private function reportOutcome(array $result): void
    {
        if ($result['imported'] === 0 && $result['unsettled'] === 0) {
            // Quiet by default: this runs every few minutes and finding
            // nothing is the normal case.
            if ($this->getOutput()->isVerbose()) {
                $this->info('No new files.');
            }

            return;
        }

        if ($result['imported'] > 0) {
            $verb = $this->option('dry-run') ? 'would be imported' : 'imported';

            $this->info($result['imported'] . ' ' . str('file')->plural($result['imported']) . ' ' . $verb);
        }

        if ($result['unsettled'] > 0) {
            $this->comment($result['unsettled'] . ' still being written — will pick up next scan');
        }
    }
}
