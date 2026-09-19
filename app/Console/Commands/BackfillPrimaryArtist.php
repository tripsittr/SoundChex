<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Console\Commands;

use App\Models\MusicMetadata;
use App\Services\ArtistCredits;
use Illuminate\Console\Command;

/**
 * Derives the primary (headline) artist for existing music rows.
 *
 * Tracks tagged with features — "Artist, Someone" or "Artist/Someone" — were
 * browsing as their own artist because primary_artist was never set at import.
 * The credit tag is left intact; this fills the separate primary_artist column
 * that grouping and browsing use, via ArtistCredits (which keeps indivisible
 * names like "Tyler, The Creator" and "Earth, Wind & Fire" whole).
 *
 * Idempotent: `--all` recomputes every row; by default it only fills the blanks.
 */
class BackfillPrimaryArtist extends Command
{
    protected $signature = 'music:backfill-primary-artist
        {--all : Recompute every row, not only those with a blank primary_artist}
        {--dry-run : Show how many would change without writing}';

    protected $description = 'Fill primary_artist from the artist credit for existing music';

    public function handle(ArtistCredits $credits): int
    {
        $query = MusicMetadata::query()->whereNotNull('artist')->where('artist', '!=', '');

        if (! $this->option('all')) {
            $query->where(fn ($q) => $q->whereNull('primary_artist')->orWhere('primary_artist', ''));
        }

        $total = (clone $query)->count();

        if ($total === 0) {
            $this->info('Nothing to backfill.');

            return self::SUCCESS;
        }

        $this->info(($this->option('dry-run') ? 'Would update ' : 'Updating ')."{$total} row".($total === 1 ? '' : 's').'…');

        $bar = $this->output->createProgressBar($total);
        $changed = 0;

        $query->chunkById(500, function ($rows) use ($credits, &$changed, $bar): void {
            foreach ($rows as $meta) {
                $primary = $credits->primary($meta->artist);

                if ($primary !== $meta->primary_artist) {
                    $changed++;

                    if (! $this->option('dry-run')) {
                        $meta->forceFill(['primary_artist' => $primary])->saveQuietly();
                    }
                }

                $bar->advance();
            }
        });

        $bar->finish();
        $this->newLine(2);

        $this->info(($this->option('dry-run') ? 'Would change ' : 'Changed ')."{$changed} row".($changed === 1 ? '' : 's').'.');

        return self::SUCCESS;
    }
}
