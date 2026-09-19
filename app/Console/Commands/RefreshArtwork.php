<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Console\Commands;

use App\Enums\MediaItemType;
use App\Models\MediaItem;
use App\Services\Metadata\CoverArtFetcher;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

/**
 * Replaces music cover art with a verified album cover fetched online (S-258).
 *
 * Embedded art is unreliable — a track can carry a compilation's cover (a
 * Stressed Out row wearing "A Sky Full of Stars"). This looks the album up
 * online, validates the artist, downloads the cover, and points the track at it.
 *
 * **Efficient:** covers belong to the album, not the track, so it groups the
 * library by artist+album and fetches once per album (≈half as many calls as
 * tracks), sharing the result across the album's tracks. CoverArtFetcher also
 * de-duplicates downloads and throttles between calls.
 *
 * `--scope`:
 *   all           every track (except hand-picked covers)
 *   missing       only tracks with no cover at all
 *   likely-wrong  tracks whose album tag looks like a compilation/playlist
 *                 (default — the ones most likely to carry the wrong cover)
 *
 * Hand-picked covers (uploaded to media/covers/) are never touched. Destructive
 * for the old extracted files, so it dry-runs unless `--force`.
 */
class RefreshArtwork extends Command
{
    /** Manually-uploaded covers live here (Filament music form) — never wiped. */
    private const MANUAL_COVER_PREFIX = 'media/covers/';

    /** Album-tag fragments that mark a compilation/playlist rather than an album. */
    private const COMPILATION_HINTS = [
        'sky full of stars', 'modern pop', 'hits', 'top 100', 'top hits', 'playlist',
        'compilation', 'now that', 'greatest hits', 'the best of', 'best of',
        'various artists', 'mix', 'workout', 'party', 'essentials', 'anthems',
    ];

    protected $signature = 'artwork:refresh
        {--scope=likely-wrong : all | missing | likely-wrong}
        {--sample=0 : Only this many albums, for a trial run (0 = all)}
        {--force : Actually fetch and replace (otherwise a dry run)}';

    protected $description = 'Fetch verified album covers online and replace mismatched music artwork';

    public function handle(CoverArtFetcher $fetcher): int
    {
        $scope = $this->option('scope');

        if (! in_array($scope, ['all', 'missing', 'likely-wrong'], true)) {
            $this->error("Unknown --scope '{$scope}'. Use all, missing, or likely-wrong.");

            return self::FAILURE;
        }

        // Group the eligible tracks by album, so each album is fetched once.
        $albums = $this->eligibleTracks($scope)
            ->groupBy(fn (MediaItem $item) => $this->albumKey($item));

        $albumCount = $albums->count();
        $trackCount = $albums->flatten()->count();

        if ($albumCount === 0) {
            $this->info('Nothing matches that scope.');

            return self::SUCCESS;
        }

        if (($sample = (int) $this->option('sample')) > 0) {
            $albums = $albums->take($sample);
            $albumCount = $albums->count();
            $trackCount = $albums->flatten()->count();
        }

        $this->info("Scope '{$scope}': {$trackCount} track"
            .($trackCount === 1 ? '' : 's')." across {$albumCount} album"
            .($albumCount === 1 ? '' : 's').'.');

        if (! $this->option('force')) {
            $this->warn("Dry run — no calls made. One lookup per album (~{$albumCount} calls). Re-run with --force.");

            return self::SUCCESS;
        }

        $bar = $this->output->createProgressBar($albumCount);
        $updated = 0;
        $noMatch = 0;
        $filesDeleted = 0;

        foreach ($albums as $tracks) {
            $first = $tracks->first();
            $cover = $fetcher->fetchForAlbum($first->musicMetadata?->artist, $first->musicMetadata?->album);

            if ($cover === null) {
                $noMatch += $tracks->count();
                $bar->advance();

                continue;
            }

            foreach ($tracks as $track) {
                $filesDeleted += $this->deleteOldCover($track);
                $track->forceFill(['cover_image_url' => $cover])->saveQuietly();
                $updated++;
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        $this->info("{$fetcher->lookupCount()} albums looked up.");
        $this->info("Updated {$updated} track".($updated === 1 ? '' : 's')
            .", deleted {$filesDeleted} old cover file".($filesDeleted === 1 ? '' : 's').'.');

        if ($noMatch > 0) {
            $this->comment("{$noMatch} track".($noMatch === 1 ? '' : 's')
                .' had no confident online match — their existing cover was left in place.');
        }

        return self::SUCCESS;
    }

    /**
     * The tracks in scope: music, never a hand-picked cover, filtered by scope.
     *
     * @return Collection<int, MediaItem>
     */
    private function eligibleTracks(string $scope): Collection
    {
        $query = MediaItem::query()
            ->where('type', MediaItemType::Music)
            ->where(fn ($q) => $q
                ->whereNull('cover_image_url')
                ->orWhere('cover_image_url', 'not like', self::MANUAL_COVER_PREFIX.'%'))
            ->with('musicMetadata');

        if ($scope === 'missing') {
            $query->whereNull('cover_image_url');
        }

        $tracks = $query->get();

        if ($scope === 'likely-wrong') {
            $tracks = $tracks->filter(fn (MediaItem $item) => $this->looksLikeCompilation($item->musicMetadata?->album));
        }

        return $tracks->values();
    }

    /** A stable per-album key so tracks on one album group together. */
    private function albumKey(MediaItem $item): string
    {
        $meta = $item->musicMetadata;

        return mb_strtolower(trim((string) $meta?->artist)).'|'.mb_strtolower(trim((string) $meta?->album));
    }

    /** Whether an album tag reads like a compilation/playlist, not a real album. */
    private function looksLikeCompilation(?string $album): bool
    {
        if (blank($album)) {
            return false;
        }

        $needle = mb_strtolower($album);

        foreach (self::COMPILATION_HINTS as $hint) {
            if (str_contains($needle, $hint)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Deletes a track's old extracted cover file, if it has one of its own.
     *
     * Only the per-track `artwork/…` extractions are removed; a shared
     * `artwork/covers/…` file (this feature's own downloads) or a remote URL is
     * left alone, since other tracks may point at it.
     *
     * @return int 1 if a file was deleted, else 0.
     */
    private function deleteOldCover(MediaItem $track): int
    {
        $path = $track->cover_image_url;

        if (blank($path) || str_starts_with($path, 'http') || str_starts_with($path, 'artwork/covers/')) {
            return 0;
        }

        return Storage::disk('public')->delete(ltrim($path, '/')) ? 1 : 0;
    }
}
