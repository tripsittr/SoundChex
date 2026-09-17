<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Services\Metadata\Sources\Movie;

use App\Enums\MediaItemType;
use App\Enums\ProcessingStatus;
use App\Models\MediaItem;
use App\Services\Metadata\Contracts\MetadataSource;
use App\Services\Metadata\Sources\Concerns\TalksToTmdb;
use App\Services\SettingsService;

/**
 * Layer 1 for movies — TMDB. Title, overview, genres, cast, crew, artwork,
 * runtime, and the IMDb id that later sources key off.
 *
 * Requires a free v3 API key, entered under Settings → Metadata Sources.
 */
class Tmdb implements MetadataSource
{
    use TalksToTmdb;

    public function __construct(private SettingsService $settings) {}

    public function name(): string { return 'TMDB'; }
    public function priority(): int { return 1; }

    public function supports(MediaItem $item): bool
    {
        return $item->type === MediaItemType::Movie
            && filled($this->apiKey())
            && (filled($item->movieMetadata?->tmdb_id) || filled($item->title));
    }

    public function enrich(MediaItem $item): void
    {
        // Captured before fillBlank writes an id — afterwards every match
        // would look as though it had been resolved by id.
        $matchedById = filled($item->movieMetadata?->tmdb_id);

        $movie = $this->resolveMovie($item);

        if ($movie === null) {
            return;
        }

        $meta = $item->movieMetadata;

        $this->fillBlank($meta, [
            'tmdb_id'         => $movie['id'] ?? null,
            'imdb_id'         => $movie['imdb_id'] ?? null,
            'release_year'    => $this->extractYear($movie['release_date'] ?? null),
            'runtime_minutes' => $movie['runtime'] ?? null,
            'tagline'         => $movie['tagline'] ?? null,
            'language'        => $movie['original_language'] ?? null,
            'country'         => $movie['production_countries'][0]['iso_3166_1'] ?? null,
            'studio'          => $movie['production_companies'][0]['name'] ?? null,
            'director'        => $this->directorName($movie['credits'] ?? []),
            'mpaa_rating'     => $this->certification($movie['release_dates'] ?? []),
        ]);

        $this->promoteTitle($item, $movie);
        $this->writeOverview($item, $movie);
        $this->writeArtwork($item, $movie);
        $this->writeGenreTags($item, $movie['genres'] ?? []);
        $this->writeCredits($item, $movie['credits'] ?? [], ['Director', 'Screenplay', 'Writer', 'Producer']);
        $this->recordConfidence($item, $movie, $matchedById);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function resolveMovie(MediaItem $item): ?array
    {
        $tmdbId = $item->movieMetadata?->tmdb_id;

        if (filled($tmdbId)) {
            return $this->fetchMovie((int) $tmdbId);
        }

        // Filenames leave the year inside the title ("Backrooms 2026"), and
        // TMDB searches that literally — no film is titled that, so it returns
        // nothing. The year has to move into its own parameter, where it
        // filters instead of being matched as text.
        [$searchTitle, $searchYear] = $this->splitTitleAndYear($item->title);

        $results = $this->request('/search/movie', [
            'query'         => $searchTitle,
            'include_adult' => false,
            // Narrows a title search when the user supplied a year.
            'year'          => $item->movieMetadata?->release_year ?? $searchYear,
        ])?->json('results') ?? [];

        // A wrong year excludes the right film outright, so a year-filtered
        // miss is retried without it rather than giving up.
        if (empty($results) && $searchYear !== null) {
            $results = $this->request('/search/movie', [
                'query'         => $searchTitle,
                'include_adult' => false,
            ])?->json('results') ?? [];
        }

        if (empty($results)) {
            return null;
        }

        // TMDB ranks by popularity, which is usually right for movies. Flag the
        // item when several titles match closely enough to be ambiguous.
        if ($this->isAmbiguous($results, $item->title)) {
            $item->processing_status = ProcessingStatus::NeedsReview;
            $item->saveQuietly();
        }

        return $this->fetchMovie((int) $results[0]['id']);
    }

    /**
     * Separates a trailing year from a title.
     *
     * Filenames carry the year as part of the name ("Backrooms 2026"), which
     * TMDB matches as literal title text and finds nothing. Splitting it lets
     * the year filter results instead.
     *
     * Only a trailing year is taken — a leading or embedded one is usually
     * part of the real title ("2001: A Space Odyssey", "Blade Runner 2049").
     *
     * @return array{0: string, 1: int|null}
     */
    private function splitTitleAndYear(string $title): array
    {
        if (! preg_match('/^(.*\S)\s+(19\d{2}|20\d{2})$/', trim($title), $match)) {
            return [trim($title), null];
        }

        return [$match[1], (int) $match[2]];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fetchMovie(int $id): ?array
    {
        // One call with appended sub-resources beats four round trips.
        return $this->request("/movie/{$id}", [
            'append_to_response' => 'credits,release_dates',
        ])?->json();
    }

    /**
     * Several same-titled results with comparable popularity means we can't be
     * confident which one the user meant.
     *
     * @param array<int, array<string, mixed>> $results
     */
    private function isAmbiguous(array $results, string $title): bool
    {
        $exact = collect($results)
            ->filter(fn (array $r) => strcasecmp($r['title'] ?? '', $title) === 0)
            ->count();

        return $exact > 1;
    }

    /**
     * @param array<string, mixed> $credits
     */
    private function directorName(array $credits): ?string
    {
        foreach ($credits['crew'] ?? [] as $member) {
            if (($member['job'] ?? '') === 'Director') {
                return $member['name'] ?? null;
            }
        }

        return null;
    }

    /**
     * US certification (G/PG/PG-13/R). TMDB nests these per country.
     *
     * @param array<string, mixed> $releaseDates
     */
    private function certification(array $releaseDates): ?string
    {
        foreach ($releaseDates['results'] ?? [] as $country) {
            if (($country['iso_3166_1'] ?? '') !== 'US') {
                continue;
            }

            foreach ($country['release_dates'] ?? [] as $release) {
                if (filled($release['certification'] ?? null)) {
                    return $release['certification'];
                }
            }
        }

        return null;
    }

    /**
     * An item added by title keeps whatever the user typed; TMDB's canonical
     * title replaces it only when it differs by more than casing.
     *
     * @param array<string, mixed> $movie
     */
    private function promoteTitle(MediaItem $item, array $movie): void
    {
        $canonical = $movie['title'] ?? null;

        if (blank($canonical) || strcasecmp($canonical, $item->title) === 0) {
            return;
        }

        $item->title = $canonical;
        $item->saveQuietly();
    }

    /**
     * @param array<string, mixed> $movie
     */
    private function writeOverview(MediaItem $item, array $movie): void
    {
        if (filled($item->notes) || blank($movie['overview'] ?? null)) {
            return;
        }

        $item->notes = $movie['overview'];
        $item->saveQuietly();
    }
}
