<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Console\Commands;

use App\Services\Metadata\AlbumTitleNormalizer;
use Illuminate\Console\Command;

/**
 * Collapses albums duplicated only by capitalization (S-303).
 *
 * Rewrites each album to the spelling most common in the library, so "Cage The
 * Elephant" and "Cage the Elephant" become one album. Shows what it would change
 * first; `--force` applies it.
 */
class NormalizeAlbumCapitalization extends Command
{
    protected $signature = 'music:normalize-albums {--force : Apply the changes (otherwise a dry run)}';

    protected $description = 'Collapse albums that differ only by capitalization to their most common spelling';

    public function handle(AlbumTitleNormalizer $normalizer): int
    {
        $groups = $normalizer->groupsNeedingNormalization();

        if ($groups->isEmpty()) {
            $this->info('No albums differ only by capitalization. Nothing to do.');

            return self::SUCCESS;
        }

        $this->line("Albums with mixed capitalization: {$groups->count()}");
        $this->newLine();

        $rowsToChange = 0;
        foreach ($groups as $group) {
            $canonical = $group['canonical'];
            $others = collect($group['variants'])
                ->reject(fn (int $c, string $spelling): bool => $spelling === $canonical);

            $this->line("  <info>{$canonical}</info>".($group['artist'] ? " — {$group['artist']}" : ''));
            foreach ($others as $spelling => $count) {
                $this->line("      <comment>{$spelling}</comment> ({$count}) → {$canonical}");
                $rowsToChange += $count;
            }
        }

        $this->newLine();

        if (! $this->option('force')) {
            $this->warn("Dry run: {$rowsToChange} track(s) across {$groups->count()} album(s) would be rewritten.");
            $this->line('Run again with --force to apply.');

            return self::SUCCESS;
        }

        $changed = $normalizer->normalizeLibrary();
        $this->info("Rewrote {$changed} track(s) to their canonical album spelling.");

        return self::SUCCESS;
    }
}
