<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Console\Commands;

use App\Models\MediaItem;
use App\Services\TitleTidier;
use Illuminate\Console\Command;

/**
 * Strips artist credits out of titles already filed (S-365).
 *
 * Title cleanup runs during enrichment, so it only ever touched tracks added
 * after it started working — and it was switched off entirely between S-321
 * and S-361. Everything filed in between kept whatever its tagger wrote:
 * "$uicideboy$, Maxo Cream - Pictures" rather than "Pictures".
 *
 * Only the title column is rewritten. The file on disk keeps its name, because
 * renaming is filing's job and a rename that fails halfway is far worse than a
 * title that reads oddly in a file manager.
 *
 * Shows what it would change first; `--force` applies it.
 */
class TidyExistingTitles extends Command
{
    protected $signature = 'music:tidy-titles
        {--force : Apply the changes (otherwise a dry run)}
        {--limit=0 : Only consider this many tracks, for a quick look}';

    protected $description = 'Strip artist credits out of music titles already in the library';

    public function handle(TitleTidier $tidier): int
    {
        $limit = (int) $this->option('limit');

        /** @var array<int, array{id: int, from: string, to: string}> $changes */
        $changes = [];

        MediaItem::query()
            ->with('musicMetadata')
            ->where('type', 'music')
            ->when($limit > 0, fn ($q) => $q->limit($limit))
            ->chunkById(500, function ($items) use ($tidier, &$changes): void {
                foreach ($items as $item) {
                    $tidied = $tidier->strip((string) $item->title, [
                        $item->musicMetadata?->artist,
                        $item->musicMetadata?->primary_artist,
                    ]);

                    if ($tidied === null || $tidied === $item->title) {
                        continue;
                    }

                    $changes[] = [
                        'id' => $item->id,
                        'from' => (string) $item->title,
                        'to' => $tidied,
                    ];
                }
            });

        if ($changes === []) {
            $this->info('No titles are carrying their artist. Nothing to do.');

            return self::SUCCESS;
        }

        foreach ($changes as $change) {
            $this->line("  <comment>{$change['from']}</comment>");
            $this->line("    → <info>{$change['to']}</info>");
        }

        $this->newLine();

        if (! $this->option('force')) {
            $this->warn('Dry run: '.count($changes).' title(s) would be rewritten.');
            $this->line('Run again with --force to apply.');

            return self::SUCCESS;
        }

        // saveQuietly: this is a correction to what was already filed, not a
        // fresh edit — observers would re-file and re-enrich a whole library
        // for a change that only tidies text.
        foreach ($changes as $change) {
            MediaItem::query()
                ->whereKey($change['id'])
                ->first()
                ?->forceFill(['title' => $change['to']])
                ->saveQuietly();
        }

        $this->info('Rewrote '.count($changes).' title(s).');

        return self::SUCCESS;
    }
}
