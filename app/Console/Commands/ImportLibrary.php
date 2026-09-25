<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Console\Commands;

use App\Jobs\EnrichMediaItemJob;
use App\Models\MediaItem;
use App\Models\User;
use App\Services\LibraryCsv;
use Illuminate\Console\Command;

/**
 * Imports a CSV into the library.
 *
 * Reads the same shape `library:export` writes, and matches columns by header
 * name, so exports from other tools work once their headers are renamed.
 */
class ImportLibrary extends Command
{
    protected $signature = 'library:import
        {path : CSV file to read}
        {--no-enrich : Skip queueing metadata enrichment for imported rows}';

    protected $description = 'Import media items from a CSV file';

    public function handle(LibraryCsv $csv): int
    {
        $path = $this->expandPath($this->argument('path'));

        if (! is_readable($path)) {
            $this->error('Not a readable file: '.$path);

            return self::FAILURE;
        }

        $userId = User::query()->min('id');

        if ($userId === null) {
            $this->error('No users exist yet. Register an account first.');

            return self::FAILURE;
        }

        // Anything created by this run gets enriched; existing rows are left
        // alone so an import never re-runs the pipeline over the whole library.
        $highestBefore = MediaItem::max('id') ?? 0;

        $this->info('Importing '.basename($path).'…');

        $result = $csv->import($path, $userId);

        $this->newLine();
        $this->info($result['imported'].' imported');

        if ($result['skipped'] > 0) {
            $this->comment($result['skipped'].' skipped (already present or invalid)');
        }

        foreach ($result['errors'] as $error) {
            $this->warn('  '.$error);
        }

        if (! $this->option('no-enrich') && $result['imported'] > 0) {
            $queued = 0;

            MediaItem::unresolved()->where('id', '>', $highestBefore)
                ->pluck('id')
                ->each(function (int $id) use (&$queued): void {
                    EnrichMediaItemJob::dispatch($id);
                    $queued++;
                });

            $this->newLine();
            $this->comment($queued.' queued for enrichment. Run `php artisan queue:work` if no worker is running.');
        }

        return self::SUCCESS;
    }

    private function expandPath(string $path): string
    {
        if (str_starts_with($path, '~/')) {
            return rtrim((string) getenv('HOME'), '/').substr($path, 1);
        }

        return $path;
    }
}
