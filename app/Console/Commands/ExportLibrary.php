<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Console\Commands;

use App\Enums\MediaItemType;
use App\Services\LibraryCsv;
use Illuminate\Console\Command;

/**
 * Writes the library to a CSV file — a plain-text backup that isn't tied to
 * this app, and the input format for `library:import`.
 */
class ExportLibrary extends Command
{
    protected $signature = 'library:export
        {path : File to write, e.g. ~/library.csv}
        {--type= : Limit to one media type (music|movie|show|book)}';

    protected $description = 'Export the library to CSV';

    public function handle(LibraryCsv $csv): int
    {
        $type = null;

        if (filled($this->option('type'))) {
            $type = MediaItemType::tryFrom($this->option('type'));

            if ($type === null) {
                $this->error('Unknown type. Use music, movie, show, or book.');

                return self::FAILURE;
            }
        }

        $path = $this->expandPath($this->argument('path'));
        $handle = fopen($path, 'wb');

        if ($handle === false) {
            $this->error('Could not write to ' . $path);

            return self::FAILURE;
        }

        $rows = 0;

        foreach ($csv->export($type) as $row) {
            fputcsv($handle, $row);
            $rows++;
        }

        fclose($handle);

        // The header is a row too, and reporting it as an item would be wrong.
        $this->info(max(0, $rows - 1) . ' items exported to ' . $path);

        return self::SUCCESS;
    }

    private function expandPath(string $path): string
    {
        if (str_starts_with($path, '~/')) {
            return rtrim((string) getenv('HOME'), '/') . substr($path, 1);
        }

        return $path;
    }
}
