<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Console\Commands;

use App\Models\MediaItem;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Fills `file_size` for items catalogued before the column existed (S-119).
 *
 * New items get their size at catalogue time; this pays the one-time
 * `filesize()` cost for the existing library so the size total becomes an exact
 * stored SUM. Chunked and idempotent — only rows still missing a size are read,
 * so it can be re-run and resumed.
 */
class BackfillFileSizes extends Command
{
    protected $signature = 'library:backfill-sizes {--all : Re-read every item, not just those missing a size}';

    protected $description = "Fill media_items.file_size from disk for items that don't have it yet";

    public function handle(): int
    {
        $query = MediaItem::query()->whereNotNull('file_path');

        if (! $this->option('all')) {
            $query->whereNull('file_size');
        }

        $total = (clone $query)->count();

        if ($total === 0) {
            $this->info('Nothing to backfill — every item already has a stored size.');

            return self::SUCCESS;
        }

        $this->info("Backfilling file sizes for {$total} item(s)…");
        $bar = $this->output->createProgressBar($total);

        $filled = 0;
        $missing = 0;

        $query->select(['id', 'file_path'])->chunkById(500, function ($items) use (&$filled, &$missing, $bar) {
            foreach ($items as $item) {
                $size = $this->sizeOf($item->file_path);

                if ($size === null) {
                    $missing++;
                } else {
                    // saveQuietly: a size backfill is not a content change and
                    // must not bump timestamps or fire enrichment events.
                    $item->forceFill(['file_size' => $size])->saveQuietly();
                    $filled++;
                }

                $bar->advance();
            }
        });

        $bar->finish();
        $this->newLine(2);
        $this->info("Filled {$filled}; {$missing} file(s) unreadable (left null).");

        return self::SUCCESS;
    }

    private function sizeOf(string $path): ?int
    {
        $absolute = str_starts_with($path, DIRECTORY_SEPARATOR) || preg_match('/^[A-Za-z]:[\\\\\/]/', $path)
            ? $path
            : Storage::path($path);

        $size = is_file($absolute) ? @filesize($absolute) : false;

        return $size === false ? null : $size;
    }
}
