<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Console\Commands;

use App\Models\MusicMetadata;
use App\Services\ArtistCredits;
use App\Services\Metadata\Sources\Music\FileTagger;
use Illuminate\Console\Command;

/**
 * Cleans a playlist-index prefix out of existing artist rows (S-275).
 *
 * A bad exporter wrote the track's position into the ARTIST tag — "7373. Queer"
 * for a track whose artist is really Garbage — and those rows were catalogued
 * and filed under that folder before it was noticed. New imports are cleaned at
 * read time by FileTagger; this fixes the ones already stored, using the exact
 * same rule so the two cannot drift.
 *
 * primary_artist is recomputed for any row that changes, so browsing groups on
 * the corrected name. Idempotent — a clean row is left untouched.
 */
class StripArtistIndexPrefix extends Command
{
    protected $signature = 'music:strip-artist-index
        {--dry-run : Show how many would change without writing}';

    protected $description = 'Remove a leading "NNNN. " playlist index from existing artist tags';

    public function handle(ArtistCredits $credits): int
    {
        // The rule needs a dot, so a candidate must at least contain one. This
        // narrows the scan; stripIndexPrefix() makes the real decision.
        $query = MusicMetadata::query()
            ->whereNotNull('artist')
            ->where('artist', 'like', '%.%');

        $changed = 0;
        $dryRun = (bool) $this->option('dry-run');

        $query->chunkById(500, function ($rows) use ($credits, &$changed, $dryRun): void {
            foreach ($rows as $meta) {
                $cleaned = FileTagger::stripIndexPrefix($meta->artist);

                if ($cleaned === $meta->artist) {
                    continue;
                }

                $changed++;
                $this->line("  {$meta->artist}  →  {$cleaned}");

                if (! $dryRun) {
                    $meta->forceFill([
                        'artist' => $cleaned,
                        'primary_artist' => $credits->primary($cleaned),
                    ])->saveQuietly();
                }
            }
        });

        $this->newLine();
        $this->info(($dryRun ? 'Would change ' : 'Changed ')."{$changed} row".($changed === 1 ? '' : 's').'.');

        return self::SUCCESS;
    }
}
