<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Services\Metadata\Sources\Music;

use App\Enums\MediaItemType;
use App\Models\MediaItem;
use App\Services\Metadata\Contracts\MetadataSource;
use Illuminate\Support\Facades\Http;

/**
 * Deezer — free public catalogue, no authentication.
 *
 * A second cover-art source alongside iTunes. It searches by artist + track
 * (not album), so a file whose album tag is a compilation ("A Sky Full of Stars
 * — Modern Pop") still finds the real recording and its album cover, which
 * album-based lookups miss. As with iTunes, a result is accepted only when its
 * artist matches the track's — better no cover than the wrong one.
 *
 * Runs late (after iTunes) and, like every cover source, only writes when no
 * cover is set yet, so it fills gaps rather than fighting a higher source.
 */
class Deezer implements MetadataSource
{
    public function name(): string
    {
        return 'Deezer';
    }

    public function priority(): int
    {
        return 10;
    }

    public function requiredSettings(): array
    {
        return []; // no key needed
    }

    public function supports(MediaItem $item): bool
    {
        return $item->type === MediaItemType::Music;
    }

    public function enrich(MediaItem $item): void
    {
        // Nothing to fill if a cover is already set by a higher source.
        if (filled($item->cover_image_url)) {
            return;
        }

        $meta = $item->musicMetadata;
        $artist = $meta?->artist;

        // Track-level search needs both the performer and the title to be sure.
        if (blank($artist) || blank($item->title)) {
            return;
        }

        // Deezer's advanced query syntax: match artist and track precisely.
        $query = sprintf('artist:"%s" track:"%s"', $artist, $item->title);

        $response = Http::get('https://api.deezer.com/search', [
            'q'     => $query,
            'limit' => 5,
        ]);

        if (! $response->ok()) {
            return;
        }

        $cover = $this->coverFrom($response->json('data', []), $artist);

        if ($cover !== null) {
            $item->cover_image_url = $cover;
            $item->saveQuietly();
        }
    }

    /**
     * The album cover of the first result whose artist matches, or null.
     *
     * Deezer nests the album image under `album.cover_xl` (1000px) / `cover_big`
     * (500px). We take the largest offered.
     *
     * @param  array<int, array<string, mixed>>  $results
     */
    private function coverFrom(array $results, string $artist): ?string
    {
        if (empty($results)) {
            return null;
        }

        $want = $this->normalise($artist);

        foreach ($results as $result) {
            $got = $this->normalise((string) ($result['artist']['name'] ?? ''));

            if ($got === '' || ! ($got === $want || str_contains($got, $want) || str_contains($want, $got))) {
                continue;
            }

            $album = $result['album'] ?? [];
            $cover = $album['cover_xl'] ?? $album['cover_big'] ?? $album['cover_medium'] ?? null;

            if (filled($cover)) {
                return $cover;
            }
        }

        return null;
    }

    /** Lower-cased, "the "-stripped, punctuation-free — for comparing artists. */
    private function normalise(string $value): string
    {
        $value = mb_strtolower(trim($value));
        $value = preg_replace('/^the\s+/', '', $value) ?? $value;

        return preg_replace('/[^a-z0-9]+/', '', $value) ?? $value;
    }
}
