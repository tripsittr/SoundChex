<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Services\Metadata\Sources\Movie;

use App\Enums\MediaItemType;
use App\Models\MediaItem;
use App\Services\Metadata\Contracts\MetadataSource;
use App\Services\SettingsService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Critic scores and awards, which nothing else carries (#504).
 *
 * `movie_metadata.imdb_rating` and `rt_score` have existed since the table was
 * created and **nothing ever wrote them** — null on every row — because this
 * source was never built and `omdb_api_key` was one of the seven keys the
 * integrations audit found that no code reads. Somebody pasting a key got a
 * green card and no change in behaviour.
 *
 * Deliberately narrow. TMDB already supplies title, overview, genres, cast,
 * crew, artwork and runtime, and it does them better; OMDb is here for the four
 * things TMDB does not have: the IMDb rating, the Rotten Tomatoes score, the
 * Metacritic score, and the awards sentence.
 *
 * Keyed by IMDb id, which TMDB writes first — hence priority 2. Searching OMDb
 * by title would re-do work TMDB has already done more accurately, and risk
 * attaching one film's ratings to another.
 */
class Omdb implements MetadataSource
{
    private const ENDPOINT = 'https://www.omdbapi.com/';

    public function __construct(private SettingsService $settings) {}

    public function name(): string
    {
        return 'OMDb';
    }

    /**
     * After TMDB, which is what supplies the IMDb id this keys off.
     */
    public function priority(): int
    {
        return 2;
    }

    public function requiredSettings(): array
    {
        return ['omdb_api_key' => 'OMDb API Key'];
    }

    public function supports(MediaItem $item): bool
    {
        if (! in_array($item->type, [MediaItemType::Movie, MediaItemType::Show], true)) {
            return false;
        }

        // An IMDb id or nothing. OMDb can search by title, but TMDB has already
        // identified this item far more carefully, and a title search here would
        // risk hanging one film's ratings on another.
        return filled($this->apiKey()) && filled($this->imdbId($item));
    }

    public function enrich(MediaItem $item): void
    {
        $imdbId = $this->imdbId($item);

        if (blank($imdbId)) {
            return;
        }

        $body = $this->fetch((string) $imdbId);

        if ($body === null) {
            return;
        }

        $values = array_filter([
            'imdb_rating' => $this->decimal($body['imdbRating'] ?? null),
            'rt_score' => $this->percent($this->ratingFrom($body, 'Rotten Tomatoes')),
            'metascore' => $this->percent($body['Metascore'] ?? null),
            'awards' => $this->awards($body['Awards'] ?? null),
        ], fn ($value) => $value !== null);

        if ($values === []) {
            return;
        }

        // Only what is still empty. A score a person corrected by hand, or one
        // a later source wrote, outranks this -- the same rule every other
        // source follows.
        $metadata = $item->type === MediaItemType::Movie
            ? $item->movieMetadata
            : $item->showMetadata;

        if ($metadata === null) {
            return;
        }

        foreach ($values as $column => $value) {
            if (blank($metadata->{$column})) {
                $metadata->{$column} = $value;
            }
        }

        $metadata->save();
    }

    /**
     * One lookup by IMDb id.
     *
     * @return array<string, mixed>|null
     */
    private function fetch(string $imdbId): ?array
    {
        try {
            $response = Http::timeout(15)->get(self::ENDPOINT, [
                'i' => $imdbId,
                'apikey' => $this->apiKey(),
                // Short plot: the overview is TMDB's job and is already stored.
                'plot' => 'short',
            ]);
        } catch (\Throwable $e) {
            Log::warning('OMDb could not be reached', [
                'imdb_id' => $imdbId,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        if (! $response->successful()) {
            Log::warning('OMDb answered with an error', [
                'imdb_id' => $imdbId,
                'status' => $response->status(),
            ]);

            return null;
        }

        $body = (array) $response->json();

        // OMDb answers **200 with a failure body** -- `{"Response":"False",
        // "Error":"Error getting data."}` -- so the status alone means nothing
        // and the body is what decides. A bad key arrives the same way, which
        // is why the error text is logged rather than swallowed.
        if (($body['Response'] ?? 'False') !== 'True') {
            Log::warning('OMDb declined the lookup', [
                'imdb_id' => $imdbId,
                'error' => $body['Error'] ?? 'no reason given',
            ]);

            return null;
        }

        return $body;
    }

    /**
     * A named score out of the `Ratings` array.
     *
     * Rotten Tomatoes appears only there, never as a top-level field, and the
     * array is absent entirely for an unrated title.
     *
     * @param  array<string, mixed>  $body
     */
    private function ratingFrom(array $body, string $source): ?string
    {
        foreach ((array) ($body['Ratings'] ?? []) as $rating) {
            if (($rating['Source'] ?? null) === $source) {
                return $rating['Value'] ?? null;
            }
        }

        return null;
    }

    /** `"9.3"` to 9.3, and OMDb's literal `"N/A"` to null. */
    private function decimal(mixed $value): ?float
    {
        $value = trim((string) $value);

        return is_numeric($value) ? (float) $value : null;
    }

    /**
     * `"89%"` or `"82/100"` or `"82"` to an integer.
     *
     * Three shapes because OMDb uses all three: Rotten Tomatoes is a
     * percentage, Metacritic is bare in one field and `x/100` in the array.
     */
    private function percent(mixed $value): ?int
    {
        $value = trim((string) $value);

        if ($value === '' || $value === 'N/A') {
            return null;
        }

        if (preg_match('/^(\d{1,3})/', $value, $match) !== 1) {
            return null;
        }

        $score = (int) $match[1];

        return $score >= 0 && $score <= 100 ? $score : null;
    }

    /**
     * The awards sentence, or null where there is nothing to say.
     *
     * Stored as OMDb writes it rather than parsed into counts: "Nominated for 7
     * Oscars. 21 wins & 43 nominations total" is what a detail page shows, and
     * parsing it would invent structure the source does not have.
     */
    private function awards(mixed $value): ?string
    {
        $value = trim((string) $value);

        return ($value === '' || $value === 'N/A') ? null : $value;
    }

    private function imdbId(MediaItem $item): ?string
    {
        return $item->type === MediaItemType::Movie
            ? $item->movieMetadata?->imdb_id
            : $item->showMetadata?->imdb_id;
    }

    private function apiKey(): ?string
    {
        return $this->settings->get('omdb_api_key');
    }
}
