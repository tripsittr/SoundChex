<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Console\Commands;

use App\Enums\MediaItemType;
use App\Models\MediaItem;
use App\Services\TitleTidier;
use Illuminate\Console\Command;

/**
 * Strips an artist a song title is carrying as a prefix or suffix (S-272).
 *
 * "$uicideboy$ - Converting" → "Converting". Enrichment does this per track as it
 * runs, but a library imported from "Artist - Title.mp3" filenames has thousands
 * of them already catalogued; this cleans the existing rows without a full
 * re-enrich. Conservative — see TitleTidier — so a title that legitimately is or
 * contains the artist is left alone.
 */
class TidyTitles extends Command
{
    protected $signature = 'music:tidy-titles
        {--dry-run : Show what would change without writing}';

    protected $description = 'Strip an artist carried in a song title (prefix or suffix)';

    public function handle(TitleTidier $tidier): int
    {
        $items = MediaItem::query()
            ->where('type', MediaItemType::Music)
            ->with('musicMetadata')
            ->get();

        $changed = 0;
        $dryRun = $this->option('dry-run');

        foreach ($items as $item) {
            $artists = array_filter([
                $item->musicMetadata?->artist,
                $item->musicMetadata?->primary_artist,
            ]);

            $cleaned = $tidier->strip((string) $item->title, $artists);

            if ($cleaned === null || $cleaned === $item->title) {
                continue;
            }

            $changed++;
            $this->line("  <fg=gray>{$item->title}</> → <info>{$cleaned}</info>");

            if (! $dryRun) {
                $item->forceFill(['title' => $cleaned])->saveQuietly();
            }
        }

        $this->newLine();
        $this->info(($dryRun ? 'Would change ' : 'Changed ')."{$changed} title".($changed === 1 ? '' : 's').'.');

        return self::SUCCESS;
    }
}
