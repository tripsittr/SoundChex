<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Services\Metadata\Sources\Music;

use App\Enums\MatchConfidence;
use App\Enums\MediaItemType;
use App\Enums\MediaTagSource;
use App\Models\MediaItem;
use App\Services\Metadata\Contracts\MetadataSource;
use Illuminate\Support\Facades\Http;

/**
 * iTunes Search API — no authentication required.
 * Provides high-res artwork, genre, and Apple catalog ID.
 */
class ItunesSearch implements MetadataSource
{
    public function name(): string
    {
        return 'iTunes Search';
    }

    public function priority(): int
    {
        return 5;
    }

    public function requiredSettings(): array
    {
        return [];
    } // no key needed

    public function supports(MediaItem $item): bool
    {
        return $item->type === MediaItemType::Music;
    }

    public function enrich(MediaItem $item): void
    {
        $meta = $item->musicMetadata;
        $query = implode(' ', array_filter([$meta?->artist, $meta?->album ?? $item->title]));

        if (empty($query)) {
            return;
        }

        $response = Http::get('https://itunes.apple.com/search', [
            'term' => $query,
            'media' => 'music',
            'entity' => 'album',
            // Several candidates, not just the first: the top hit is often the
            // wrong artist (a loose term match), so we pick the one whose artist
            // actually matches rather than trusting position.
            'limit' => 5,
            'country' => 'US',
        ]);

        if (! $response->ok()) {
            return;
        }

        $result = $this->bestMatch($response->json('results', []), $meta?->artist);

        if (empty($result)) {
            return;
        }

        // Only write cover if not already set by a higher-priority source
        if (empty($item->cover_image_url) && ! empty($result['artworkUrl100'])) {
            $item->cover_image_url = str_replace('100x100', '600x600', $result['artworkUrl100']);
            $item->saveQuietly();
        }

        if (! empty($result['primaryGenreName'])) {
            $item->tags()->firstOrCreate(
                ['type' => 'genre', 'value' => $result['primaryGenreName']],
                ['source' => MediaTagSource::Api->value],
            );
        }

        // iTunes matched the track by an (artist-validated) search, not an id, so
        // it's a Fuzzy match — recorded only when nothing stronger has, so the
        // library stops reading as entirely unmatched even where MusicBrainz
        // found nothing. Never downgrades an Exact match.
        if ($item->match_confidence !== MatchConfidence::Exact
            && $item->match_confidence !== MatchConfidence::Fuzzy) {
            $item->forceFill([
                'match_confidence' => MatchConfidence::Fuzzy,
                'matched_by' => $this->name(),
            ])->saveQuietly();
        }
    }

    /**
     * The first candidate whose artist matches the track's, or null.
     *
     * The old code took `results.0` unconditionally, so a loose term match
     * returned the wrong album — and its art (a Stressed Out row wearing A Sky
     * Full of Stars' cover). Requiring the artist to match stops that. When we
     * do not know the track's own artist we cannot validate, so we decline
     * rather than attach some arbitrary album's art.
     *
     * @param  array<int, array<string, mixed>>  $results
     * @return array<string, mixed>|null
     */
    private function bestMatch(array $results, ?string $artist): ?array
    {
        if (empty($results)) {
            return null;
        }

        // No known artist to check against — refuse rather than guess.
        if (blank($artist)) {
            return null;
        }

        $want = $this->normalise($artist);

        foreach ($results as $result) {
            $got = $this->normalise((string) ($result['artistName'] ?? ''));

            // Exact after normalising, or one contains the other — "Weezer" vs
            // "Weezer feat. …", "The Beatles" vs "Beatles".
            if ($got !== '' && ($got === $want
                || str_contains($got, $want)
                || str_contains($want, $got))) {
                return $result;
            }
        }

        return null;
    }

    /** Lower-cased, trimmed, punctuation-light — for comparing artist names. */
    private function normalise(string $value): string
    {
        $value = mb_strtolower(trim($value));

        // Drop "the " prefix and non-alphanumerics so "The Beatles!" == "beatles".
        $value = preg_replace('/^the\s+/', '', $value) ?? $value;

        return preg_replace('/[^a-z0-9]+/', '', $value) ?? $value;
    }
}
