<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Console\Commands;

use App\Models\MediaItem;
use App\Services\ConversionFiler;
use Illuminate\Console\Command;

/**
 * Files existing conversions into the library and archives their originals.
 *
 * For the arrangement that came before: conversions written to media/converted/
 * and reachable only through a database column. One of them became unplayable
 * when the catalogue was rebuilt, because a scan cannot see a folder it is told
 * to skip.
 *
 * Dry run by default. This moves real media, and a mistake here is measured in
 * gigabytes.
 */
class FileConversions extends Command
{
    protected $signature = 'library:file-conversions
        {--apply : Actually move the files. Without this, nothing is touched.}';

    protected $description = 'File converted copies into the library and archive the originals';

    public function handle(ConversionFiler $filer): int
    {
        $items = MediaItem::whereNotNull('converted_path')->get()
            ->filter(fn (MediaItem $item): bool => $item->hasConvertedCopy());

        if ($items->isEmpty()) {
            $this->info('No conversions to file.');

            return self::SUCCESS;
        }

        $apply = (bool) $this->option('apply');

        $this->line('');

        if (! $apply) {
            $this->comment('Dry run. Nothing will be moved — pass --apply to do it.');
            $this->line('');
        }

        $done = 0;

        foreach ($items as $item) {
            $plan = $filer->promote($item, dryRun: ! $apply);

            if ($plan === null) {
                $this->line("  <fg=red>failed</>  {$item->title}");

                continue;
            }

            $this->line("  <fg=green>" . ($apply ? 'filed ' : 'would') . "</>  {$item->title}");
            $this->line("          play    {$plan['filed']}");
            $this->line("          archive {$plan['archived']}");

            $done++;
        }

        $this->newLine();
        $this->info(sprintf(
            '%s %d conversion%s.',
            $apply ? 'Filed' : 'Would file',
            $done,
            $done === 1 ? '' : 's',
        ));

        return self::SUCCESS;
    }
}
