<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Services\Metadata\Sources\Music;

use App\Enums\MediaItemType;
use App\Enums\MediaTagSource;
use App\Models\MediaItem;
use App\Services\Metadata\Contracts\MetadataSource;
use App\Services\Metadata\LookupCache;
use App\Services\SettingsService;
use Illuminate\Support\Facades\Http;

/**
 * Last.fm — community tags, and the identifiers nothing else here supplies.
 *
 * The tags are the advertised reason for this source and the smaller half of
 * what it is worth. On the real library only 10 tracks of 8,313 carry an ISRC,
 * 28 a MusicBrainz recording id and none an AcoustID fingerprint — so every
 * duplicate decision and every exact lookup falls back to matching tag text.
 * Last.fm answers a plain artist-and-title query with MusicBrainz ids for both
 * the recording and its release, which is the one cheap way to raise that
 * coverage without a fingerprinting key.
 *
 * Those ids are written only where the field is empty, so a higher source's
 * answer always wins. Note the ordering: this runs at priority 7, after
 * MusicBrainz at 3, so an id it supplies is not used by MusicBrainz until the
 * item is enriched again. Re-enrichment is a button, and it is worth pressing
 * once after this source is first switched on.
 *
 * ## Why the tags are filtered
 *
 * Last.fm's tags are a folksonomy, not a genre list: alongside "shoegaze" and
 * "post-punk" sit "seen live", "favourites", "albums i own" and "00s". Written
 * straight into `media_tags` as genres they would reach the genre filter and
 * the genre chart, which are the one curated facet in the library — and a
 * genre list containing "seen live" is a genre list nobody trusts again.
 *
 * So each tag has to survive `metadata_rules.tag_blocklist` before it is kept,
 * and at most three are taken. A tag that is a decade, a personal note or a
 * judgement is discarded rather than canonicalised: there is no genre it was
 * trying to be.
 */
class Lastfm implements MetadataSource
{
    private const ENDPOINT = 'https://ws.audioscrobbler.com/2.0/';

    public function name(): string
    {
        return 'Last.fm';
    }

    public function priority(): int
    {
        return 7;
    }

    public function requiredSettings(): array
    {
        // key => label, not a bare list. A list makes the key the label, so
        // the settings page would have asked for "0" (#490).
        return ['lastfm_api_key' => 'Last.fm API Key'];
    }

    public function supports(MediaItem $item): bool
    {
        if ($item->type !== MediaItemType::Music) {
            return false;
        }

        // A query needs both halves; Last.fm has no search by file.
        $meta = $item->musicMetadata;
        $artist = $meta?->primary_artist ?: $meta?->artist;

        return filled(app(SettingsService::class)->get('lastfm_api_key'))
            && filled($artist)
            && filled($item->title);
    }

    public function enrich(MediaItem $item): void
    {
        $meta = $item->musicMetadata;

        if ($meta === null) {
            return;
        }

        // The headline artist, so "Artist, Someone" still searches for the lead
        // — the same key the grouping and dedup use.
        $artist = (string) ($meta->primary_artist ?: $meta->artist);

        $track = $this->track($artist, (string) $item->title);

        if ($track === null) {
            return;
        }

        $this->fillIdentifiers($item, $track);
        $this->fillCover($item, $track);
        $this->writeTags($item, $track);
    }

    /**
     * `track.getInfo`, cached.
     *
     * `autocorrect=1` so a misspelled tag still resolves — Last.fm maps it to
     * the canonical artist, which is the whole reason to ask them rather than
     * matching strings here.
     *
     * @return array<string, mixed>|null
     */
    private function track(string $artist, string $title): ?array
    {
        $parameters = [
            'method' => 'track.getInfo',
            'artist' => $artist,
            'track' => $title,
            'autocorrect' => 1,
            'format' => 'json',
        ];

        // Cached on the query without the key, so the key never lands in a
        // cache key and two installs share nothing.
        $body = app(LookupCache::class)->remember($this->name(), $parameters, function () use ($parameters): ?array {
            $response = Http::acceptJson()
                ->timeout(10)
                ->retry(2, 1000, throw: false)
                ->get(self::ENDPOINT, $parameters + [
                    'api_key' => (string) app(SettingsService::class)->get('lastfm_api_key'),
                ]);

            // Null only for a failed request, so one bad minute is not cached.
            // Last.fm answers "no such track" with a 404 and an error body,
            // which is an answer: an empty array, remembered.
            if ($response->status() === 404) {
                return [];
            }

            return $response->successful() ? ($response->json() ?? []) : null;
        });

        if ($body === null) {
            return null;
        }

        $track = data_get($body, 'track');

        return is_array($track) ? $track : null;
    }

    /**
     * The MusicBrainz ids, where this library has none.
     *
     * Empty fields only. A recording id from file tags or from MusicBrainz
     * itself is better evidence than one inferred from an artist-and-title
     * query, and both of those run earlier.
     *
     * @param  array<string, mixed>  $track
     */
    private function fillIdentifiers(MediaItem $item, array $track): void
    {
        $meta = $item->musicMetadata;

        if ($meta === null) {
            return;
        }

        $values = [];

        // Last.fm returns an empty string rather than omitting the key.
        $recording = (string) data_get($track, 'mbid', '');

        if (blank($meta->musicbrainz_recording_id) && $this->looksLikeMbid($recording)) {
            $values['musicbrainz_recording_id'] = $recording;
        }

        $release = (string) data_get($track, 'album.mbid', '');

        if (blank($meta->musicbrainz_release_id) && $this->looksLikeMbid($release)) {
            $values['musicbrainz_release_id'] = $release;
        }

        if ($values === []) {
            return;
        }

        $meta->forceFill($values)->saveQuietly();
    }

    /**
     * A UUID, and not something else in a field that should hold one.
     *
     * Checked rather than trusted: an id written into `musicbrainz_recording_id`
     * is used afterwards as a lookup key and as a duplicate signal, and a
     * malformed one would quietly pair two unrelated tracks.
     */
    private function looksLikeMbid(string $value): bool
    {
        return preg_match('~^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$~i', $value) === 1;
    }

    /**
     * Album art, when nothing above has set a cover.
     *
     * Largest first. Last.fm lists sizes smallest to largest and sometimes ends
     * with "mega"; taking the last non-empty url avoids hardcoding which sizes
     * exist on any given release.
     *
     * @param  array<string, mixed>  $track
     */
    private function fillCover(MediaItem $item, array $track): void
    {
        if (filled($item->cover_image_url)) {
            return;
        }

        $images = data_get($track, 'album.image');

        if (! is_array($images)) {
            return;
        }

        $url = collect($images)
            ->pluck('#text')
            ->filter(fn ($candidate): bool => is_string($candidate) && trim($candidate) !== '')
            ->last();

        if (! is_string($url)) {
            return;
        }

        $item->cover_image_url = $url;
        $item->saveQuietly();
    }

    /**
     * The usable tags, as genres.
     *
     * @param  array<string, mixed>  $track
     */
    private function writeTags(MediaItem $item, array $track): void
    {
        $tags = data_get($track, 'toptags.tag');

        if (! is_array($tags)) {
            return;
        }

        $aliases = config('metadata_rules.genre_aliases', []);
        $kept = 0;

        foreach ($tags as $tag) {
            if ($kept >= 3) {
                return;
            }

            $name = is_array($tag) ? (string) ($tag['name'] ?? '') : '';

            if (! $this->isGenre($name)) {
                continue;
            }

            $canonical = $aliases[mb_strtolower($name)] ?? str($name)->title()->toString();

            $exists = $item->tags()
                ->where('type', 'genre')
                ->where('value', $canonical)
                ->exists();

            if ($exists) {
                // Already known from a better source; not a reason to stop
                // looking at the rest.
                continue;
            }

            $item->tags()->create([
                'type' => 'genre',
                'value' => $canonical,
                'source' => MediaTagSource::Api,
            ]);

            $kept++;
        }
    }

    /**
     * Whether a community tag is plausibly a genre.
     *
     * Rejected rather than rewritten. "seen live" is not a mis-spelled genre,
     * and a blocklist that tried to canonicalise it would invent one.
     */
    private function isGenre(string $name): bool
    {
        $name = trim($name);

        if ($name === '' || mb_strlen($name) < 2 || mb_strlen($name) > 40) {
            return false;
        }

        $lower = mb_strtolower($name);

        // A decade or a year: real, and not a genre. "80s", "1990s", "2010".
        if (preg_match('~^(19|20)?\d{2}0?s?$~', $lower) === 1) {
            return false;
        }

        foreach ((array) config('metadata_rules.tag_blocklist', []) as $pattern) {
            if (preg_match('~'.$pattern.'~i', $lower) === 1) {
                return false;
            }
        }

        return true;
    }
}
