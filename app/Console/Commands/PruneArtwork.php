<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Console\Commands;

use App\Models\MediaItem;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Finder\Finder;

/**
 * Deletes extracted artwork no catalogue row points at.
 *
 * Cover art is written as artwork/{artist}/{album}/{song}-{id}.jpg, where the
 * id is the media item's. Anything that changes the id — recovering a lost
 * catalogue, most obviously — makes the old files unreachable while the new
 * ones sit beside them, and the folder doubles for no benefit.
 *
 * Deliberately conservative. It deletes only files inside the artwork folder,
 * only those no row references, and it will not run against a library with no
 * artwork rows at all — which would be the signature of a database that failed
 * to load rather than a library with genuinely orphaned files.
 */
class PruneArtwork extends Command
{
    protected $signature = 'artwork:prune {--dry-run : List what would be deleted without deleting it}';

    protected $description = 'Delete extracted artwork that no media item references';

    public function handle(): int
    {
        $disk = Storage::disk('public');
        $root = $disk->path('artwork');

        if (! is_dir($root)) {
            $this->info('No artwork folder.');

            return self::SUCCESS;
        }

        // Every locally-extracted cover path, as stored. Remote URLs are
        // ignored: they point at somebody else's server and own no file here.
        $referenced = MediaItem::unresolved()
            ->whereNotNull('cover_image_url')
            ->pluck('cover_image_url')
            ->reject(fn (string $path) => str_starts_with($path, 'http'))
            ->map(fn (string $path) => $disk->path(ltrim($path, '/')))
            ->flip();

        if ($referenced->isEmpty()) {
            // A catalogue that references no artwork at all is far more likely
            // to be a database that has not finished loading than a library
            // whose every cover is genuinely orphaned. Deleting on that reading
            // would remove the lot.
            $this->error('No media item references any local artwork.');
            $this->line('Refusing to treat every file as an orphan. Run the enrichment first.');

            return self::FAILURE;
        }

        $files = (new Finder)->files()->in($root);

        $orphans = [];
        $bytes = 0;

        foreach ($files as $file) {
            $path = $file->getRealPath();

            if ($path === false || $referenced->has($path)) {
                continue;
            }

            $orphans[] = $path;
            $bytes += $file->getSize();
        }

        if ($orphans === []) {
            $this->info('Nothing to prune.');

            return self::SUCCESS;
        }

        foreach (array_slice($orphans, 0, 10) as $path) {
            $this->line('  - '.str_replace($root.'/', '', $path));
        }

        if (count($orphans) > 10) {
            $this->line(sprintf('  … and %d more', count($orphans) - 10));
        }

        $this->newLine();

        if ($this->option('dry-run')) {
            $this->comment(sprintf(
                'Would delete %d file%s, freeing %s.',
                count($orphans),
                count($orphans) === 1 ? '' : 's',
                $this->humanBytes($bytes),
            ));

            return self::SUCCESS;
        }

        foreach ($orphans as $path) {
            @unlink($path);
        }

        $this->info(sprintf(
            'Deleted %d file%s, freeing %s.',
            count($orphans),
            count($orphans) === 1 ? '' : 's',
            $this->humanBytes($bytes),
        ));

        return self::SUCCESS;
    }

    private function humanBytes(int $bytes): string
    {
        return $bytes >= 1_048_576
            ? round($bytes / 1_048_576, 1).' MB'
            : round($bytes / 1024).' KB';
    }
}
