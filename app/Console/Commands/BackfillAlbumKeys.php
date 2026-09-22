<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Console\Commands;

use App\Models\MusicMetadata;
use App\Services\Metadata\AlbumTitleNormalizer;
use Illuminate\Console\Command;

/**
 * Fills `album_key` for existing tracks (S-308).
 *
 * New and edited rows get their key from the model's saving hook; this backfills
 * everything already in the library so the album browse groups on the key from
 * the start. Idempotent — safe to run again.
 */
class BackfillAlbumKeys extends Command
{
    protected $signature = 'music:backfill-album-keys';

    protected $description = 'Compute the canonical album_key for every track that lacks one';

    public function handle(AlbumTitleNormalizer $normalizer): int
    {
        // Compute the key once per distinct album spelling, then update all its
        // rows in one statement — far fewer queries than saving row by row.
        $albums = MusicMetadata::query()
            ->whereNotNull('album')
            ->where('album', '!=', '')
            ->distinct()
            ->pluck('album');

        $updated = 0;

        foreach ($albums as $album) {
            $key = $normalizer->canonicalKey((string) $album);

            $updated += MusicMetadata::query()
                ->where('album', $album)
                ->where(fn ($q) => $q->whereNull('album_key')->orWhere('album_key', '!=', $key))
                ->update(['album_key' => $key]);
        }

        // Tracks with no album have no key.
        MusicMetadata::query()
            ->where(fn ($q) => $q->whereNull('album')->orWhere('album', ''))
            ->whereNotNull('album_key')
            ->update(['album_key' => null]);

        $this->info("Set album_key on {$updated} track(s).");

        return self::SUCCESS;
    }
}
