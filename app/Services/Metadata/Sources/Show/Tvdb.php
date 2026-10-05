<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Services\Metadata\Sources\Show;

use App\Enums\MediaItemType;
use App\Models\MediaItem;
use App\Models\ShowMetadata;
use App\Services\Metadata\Contracts\MetadataSource;
use App\Services\SettingsService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * TVDB — episode artwork, and the fields TMDB leaves empty.
 *
 * TMDB runs first and answers most of what a series needs, so this is not a
 * second opinion: it fills blanks and adds the one thing TMDB is weakest at,
 * which is per-episode imagery. An episode row whose cover is empty shows a
 * placeholder in every list it appears in, and on a library of 293 episodes
 * that is most of the television section.
 *
 * `show_metadata.tvdb_id` has existed since the table was created and nothing
 * has ever written to it. Storing it is the other half of the value: it is the
 * id that alternate episode orderings are keyed by, which is the next thing
 * anyone will want for anime and for series whose DVD order differs from the
 * broadcast one.
 *
 * ## Authentication
 *
 * TVDB v4 takes an api key over POST and returns a bearer token good for about
 * a month. The token is cached for a week — long enough to cost one login per
 * sweep, short enough that a revoked key stops working in days rather than
 * being trusted until it expires. A login failure is logged and the source
 * declines rather than throwing: a bad key must not fail the whole pipeline for
 * an item TMDB has already enriched.
 *
 * ## What it does not do
 *
 * Nothing here overwrites. Every write goes through a blank check, so TMDB's
 * title, overview and artwork stand, and a value typed by hand stands over
 * both.
 */
class Tvdb implements MetadataSource
{
    private const BASE = 'https://api4.thetvdb.com/v4';

    /** Shorter than the token's real life, so a revoked key stops working. */
    private const TOKEN_DAYS = 7;

    public function __construct(private SettingsService $settings) {}

    public function name(): string
    {
        return 'TVDB';
    }

    public function priority(): int
    {
        return 3;
    }

    public function requiredSettings(): array
    {
        return ['tvdb_api_key'];
    }

    public function supports(MediaItem $item): bool
    {
        return $item->type === MediaItemType::Show
            && filled($this->settings->get('tvdb_api_key'))
            // Either an id to look up by, or a name to search for. An episode
            // searches by its series, which is checked in enrich().
            && (filled($item->showMetadata?->tvdb_id) || filled($item->title) || $item->isEpisode());
    }

    public function enrich(MediaItem $item): void
    {
        if ($item->showMetadata === null) {
            return;
        }

        if ($item->isEpisode()) {
            $this->enrichEpisode($item);

            return;
        }

        $this->enrichSeries($item);
    }

    /**
     * The series' own row: its TVDB id, and whatever TMDB left blank.
     */
    private function enrichSeries(MediaItem $item): void
    {
        $meta = $item->showMetadata;

        if ($meta === null) {
            return;
        }

        $id = $this->seriesId($item);

        if ($id === null) {
            return;
        }

        $series = $this->get("/series/{$id}/extended");

        if ($series === null) {
            return;
        }

        $data = data_get($series, 'data');

        if (! is_array($data)) {
            return;
        }

        $this->fillBlank($meta, [
            'tvdb_id' => $id,
            // TVDB's `latestNetwork`/`originalNetwork` both appear depending on
            // the series; either is better than nothing when TMDB had none.
            'network' => data_get($data, 'originalNetwork.name') ?? data_get($data, 'latestNetwork.name'),
            'status' => $this->normaliseStatus(data_get($data, 'status.name')),
            'first_air_year' => $this->year(data_get($data, 'firstAired')),
            'last_air_year' => $this->year(data_get($data, 'lastAired')),
            'content_rating' => $this->contentRating($data),
        ]);
    }

    /**
     * An episode's artwork, and its title and air date if nothing has them.
     *
     * The numbering came from the filename and is authoritative about which
     * file this is — TVDB is asked only what that episode looks like and is
     * called, never which episode it is.
     */
    private function enrichEpisode(MediaItem $item): void
    {
        $meta = $item->showMetadata;
        $series = $item->series;

        if ($meta === null || $series === null) {
            return;
        }

        if ($meta->season_number === null || $meta->episode_number === null) {
            return;
        }

        // The series' id, which its own enrichment stores. Not resolved from
        // the episode: an episode's title is a filename fragment and searching
        // TVDB with it finds the wrong series or none.
        $seriesId = $series->showMetadata?->tvdb_id;

        if (blank($seriesId)) {
            return;
        }

        $episode = $this->episode((int) $seriesId, (int) $meta->season_number, (int) $meta->episode_number);

        if ($episode === null) {
            return;
        }

        $this->fillBlank($meta, [
            'tvdb_id' => $seriesId,
            'episode_title' => data_get($episode, 'name'),
            'episode_air_date' => data_get($episode, 'aired'),
        ]);

        $this->fillEpisodeArtwork($item, $episode);
    }

    /**
     * One episode, from the series' default-order episode list.
     *
     * Asked for by season so the response stays small; TVDB pages the full
     * list and a long-running series would otherwise be several requests to
     * find one episode.
     *
     * @return array<string, mixed>|null
     */
    private function episode(int $seriesId, int $season, int $number): ?array
    {
        $body = $this->get("/series/{$seriesId}/episodes/default", [
            'season' => $season,
            'episodeNumber' => $number,
        ]);

        $episodes = data_get($body, 'data.episodes');

        if (! is_array($episodes)) {
            return null;
        }

        foreach ($episodes as $episode) {
            if (! is_array($episode)) {
                continue;
            }

            // Checked rather than trusted: the filter is a query parameter, and
            // writing an episode's name onto the wrong file is exactly the
            // failure this source should not introduce.
            if ((int) data_get($episode, 'seasonNumber', -1) === $season
                && (int) data_get($episode, 'number', -1) === $number) {
                return $episode;
            }
        }

        return null;
    }

    /**
     * The episode's still, when the row has no cover.
     *
     * This is the reason to have this source at all: TMDB's episode stills are
     * patchy, and an episode without one is a placeholder in every list.
     *
     * @param  array<string, mixed>  $episode
     */
    private function fillEpisodeArtwork(MediaItem $item, array $episode): void
    {
        if (filled($item->cover_image_url)) {
            return;
        }

        $image = data_get($episode, 'image');

        if (! is_string($image) || trim($image) === '') {
            return;
        }

        // TVDB returns some paths absolute and some relative to its artwork
        // host, and a relative one stored as-is renders as a broken image.
        $item->cover_image_url = str_starts_with($image, 'http')
            ? $image
            : 'https://artworks.thetvdb.com'.(str_starts_with($image, '/') ? '' : '/').$image;

        $item->saveQuietly();
    }

    /**
     * The series' TVDB id: the one already stored, or the best search hit.
     */
    private function seriesId(MediaItem $item): ?int
    {
        $known = $item->showMetadata?->tvdb_id;

        if (filled($known)) {
            return (int) $known;
        }

        if (blank($item->title)) {
            return null;
        }

        $body = $this->get('/search', [
            'query' => (string) $item->title,
            'type' => 'series',
            'limit' => 5,
        ]);

        $results = data_get($body, 'data');

        if (! is_array($results)) {
            return null;
        }

        foreach ($results as $result) {
            // TVDB returns the id as "series-12345" in search results and as a
            // bare integer elsewhere; both appear in the wild.
            $id = data_get($result, 'tvdb_id') ?? data_get($result, 'id');

            if (is_string($id) && preg_match('~(\d+)$~', $id, $m) === 1) {
                return (int) $m[1];
            }

            if (is_int($id) || (is_string($id) && ctype_digit($id))) {
                return (int) $id;
            }
        }

        return null;
    }

    /**
     * A GET against TVDB with a bearer token.
     *
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>|null
     */
    private function get(string $path, array $query = []): ?array
    {
        $token = $this->token();

        if ($token === null) {
            return null;
        }

        $response = Http::withToken($token)
            ->acceptJson()
            ->timeout(10)
            ->retry(2, 1000, throw: false)
            ->get(self::BASE.$path, $query);

        if ($response->status() === 401) {
            // The token was rejected. Dropped so the next call logs in again
            // rather than repeating a request that cannot succeed.
            Cache::forget($this->tokenKey());

            Log::warning('TVDB rejected the cached token', ['path' => $path]);

            return null;
        }

        if (! $response->successful()) {
            Log::warning('A TVDB request failed', [
                'path' => $path,
                'status' => $response->status(),
            ]);

            return null;
        }

        return $response->json() ?? [];
    }

    /** The bearer token, logging in if there is not one cached. */
    private function token(): ?string
    {
        $key = (string) $this->settings->get('tvdb_api_key');

        if (blank($key)) {
            return null;
        }

        $cached = Cache::get($this->tokenKey());

        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $response = Http::acceptJson()
            ->timeout(10)
            ->post(self::BASE.'/login', ['apikey' => $key]);

        if (! $response->successful()) {
            // Said out loud: a wrong key is otherwise a source that silently
            // does nothing, which looks identical to a source with no data.
            Log::error('Could not log in to TVDB', [
                'status' => $response->status(),
                'hint' => 'Check the TVDB key under Settings → Integrations.',
            ]);

            return null;
        }

        $token = data_get($response->json(), 'data.token');

        if (! is_string($token) || $token === '') {
            Log::error('TVDB logged in but returned no token');

            return null;
        }

        Cache::put($this->tokenKey(), $token, now()->addDays(self::TOKEN_DAYS));

        return $token;
    }

    /**
     * Keyed by the api key's hash, so changing the key does not reuse a token
     * issued for the old one.
     */
    private function tokenKey(): string
    {
        return 'tvdb.token.'.hash('xxh128', (string) $this->settings->get('tvdb_api_key'));
    }

    /**
     * TVDB's status wording, mapped onto what this app already stores.
     *
     * The column holds TMDB's vocabulary because TMDB wrote it first; a second
     * source using its own words would make the field mean two things.
     */
    private function normaliseStatus(?string $status): ?string
    {
        if (blank($status)) {
            return null;
        }

        return match (mb_strtolower(trim($status))) {
            'continuing' => 'Returning Series',
            'ended' => 'Ended',
            'upcoming' => 'In Production',
            default => $status,
        };
    }

    /**
     * The content rating, preferring a US one when several are listed.
     *
     * TVDB returns every certification a series has in every country, and a
     * field holding one value has to pick. Matching the existing data, which
     * came from TMDB and is US-biased, keeps the column comparable.
     *
     * @param  array<string, mixed>  $data
     */
    private function contentRating(array $data): ?string
    {
        $ratings = data_get($data, 'contentRatings');

        if (! is_array($ratings) || $ratings === []) {
            return null;
        }

        $us = collect($ratings)->first(
            fn ($rating): bool => is_array($rating) && mb_strtolower((string) ($rating['country'] ?? '')) === 'usa'
        );

        $chosen = $us ?? $ratings[0];

        $name = is_array($chosen) ? ($chosen['name'] ?? null) : null;

        return is_string($name) && $name !== '' ? $name : null;
    }

    private function year(?string $date): ?int
    {
        if (blank($date) || preg_match('~(\d{4})~', $date, $m) !== 1) {
            return null;
        }

        return (int) $m[1];
    }

    /**
     * Writes only fields that are still empty.
     *
     * @param  array<string, mixed>  $values
     */
    private function fillBlank(ShowMetadata $meta, array $values): void
    {
        $changed = false;

        foreach ($values as $column => $value) {
            if (blank($value) || filled($meta->{$column})) {
                continue;
            }

            $meta->{$column} = $value;
            $changed = true;
        }

        if ($changed) {
            $meta->saveQuietly();
        }
    }
}
