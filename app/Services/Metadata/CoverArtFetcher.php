<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Services\Metadata;

use App\Plugins\Contracts\CoverSource;
use App\Plugins\Registry;
use App\Services\SettingsService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/**
 * Fetches a verified album cover from an online source and stores it locally.
 *
 * Built for the artwork refresh (S-258), where embedded art is unreliable — a
 * track can carry a compilation's cover — so a *verified* album cover is worth
 * more than whatever was baked into the file.
 *
 * **Efficient by design.** Covers are a property of the album, not the track, so
 * this fetches strictly per album: one lookup per artist+album, the result shared
 * across every track on that album. Within a run it memoises by artist+album, so
 * a repeated album costs nothing; and it stores one file per *unique image*
 * (keyed by the source URL), so the whole library shares a cover file rather than
 * writing thousands of identical copies. A small throttle keeps it under the
 * public API's rate limits.
 *
 * Correctness comes from validation: a candidate is accepted only when its artist
 * matches the album's. A wrong match returns nothing rather than the wrong cover.
 */
class CoverArtFetcher
{
    /** Where fetched covers are written on the public disk. */
    private const COVER_DIR = 'artwork/covers';

    /** Per-run memo: "artist|album" (normalised) => stored path or null. */
    private array $albumCache = [];

    /** Milliseconds to wait between outbound API calls (rate-limit courtesy). */
    private int $throttleMs;

    public function __construct(?int $throttleMs = null)
    {
        $this->throttleMs = $throttleMs ?? (int) config('library.cover_fetch_throttle_ms', 200);
    }

    /**
     * The stored local path of a verified cover for this album, or null.
     *
     * Cached per run, so calling it once per track on an album still makes just
     * one API call and one download for the whole album.
     */
    public function fetchForAlbum(?string $artist, ?string $album): ?string
    {
        // Without an artist there is nothing to validate a result against, so a
        // fetch could only guess — decline.
        if (blank($artist)) {
            return null;
        }

        $key = $this->cacheKey($artist, $album);

        if (array_key_exists($key, $this->albumCache)) {
            return $this->albumCache[$key];
        }

        // iTunes first; Deezer as an opt-in fallback for albums it misses; then
        // any cover source a plugin contributed (S-264, #280), for a provider
        // the core does not reach.
        $url = $this->itunesCoverUrl($artist, $album)
            ?? $this->deezerCoverUrl($artist, $album)
            ?? $this->pluginCoverUrl($artist, $album);

        $path = $url === null ? null : $this->store($url);

        return $this->albumCache[$key] = $path;
    }

    /** Number of albums looked up this run (for reporting). */
    public function lookupCount(): int
    {
        return count($this->albumCache);
    }

    // ---------------------------------------------------------------- lookup

    /**
     * A cover URL from a plugin-contributed source, tried in priority order
     * until one returns something (S-264, #280).
     *
     * Each source is resolved through the container and asked; a source that
     * throws is logged and skipped so one plugin cannot break cover fetching.
     */
    private function pluginCoverUrl(string $artist, ?string $album): ?string
    {
        foreach (app(Registry::class)->coverSourceClasses() as $class) {
            if (! class_exists($class)) {
                continue;
            }

            try {
                $source = app($class);

                if ($source instanceof CoverSource) {
                    $url = $source->coverUrlFor($artist, $album);

                    if (filled($url)) {
                        return $url;
                    }
                }
            } catch (\Throwable $e) {
                report($e);
            }
        }

        return null;
    }

    /**
     * A verified iTunes artwork URL for the album, at full resolution, or null.
     *
     * Asks for a few candidates and takes the first whose artist matches — the
     * top hit is often a loose term match for a different artist.
     */
    private function itunesCoverUrl(string $artist, ?string $album): ?string
    {
        $this->throttle();

        $term = trim($artist.' '.($album ?? ''));

        $response = Http::get('https://itunes.apple.com/search', [
            'term' => $term,
            'media' => 'music',
            'entity' => 'album',
            'limit' => 5,
            'country' => 'US',
        ]);

        if (! $response->ok()) {
            return null;
        }

        $want = $this->normalise($artist);

        foreach ($response->json('results', []) as $result) {
            $got = $this->normalise((string) ($result['artistName'] ?? ''));

            if ($got === '' || ! $this->artistMatches($got, $want)) {
                continue;
            }

            $art = $result['artworkUrl100'] ?? null;

            if (filled($art)) {
                // 100x100 thumbnail → 600x600 by URL convention.
                return str_replace('100x100', '600x600', $art);
            }
        }

        return null;
    }

    /**
     * A verified Deezer album-cover URL, or null. Only when the Deezer
     * integration is enabled (S-259); it is a fallback for albums iTunes misses,
     * matched track-level so a compilation-tagged album still finds the real one.
     */
    private function deezerCoverUrl(string $artist, ?string $album): ?string
    {
        if (! app(SettingsService::class)->get('deezer_enabled')) {
            return null;
        }

        $this->throttle();

        $query = $album !== null && $album !== ''
            ? sprintf('artist:"%s" album:"%s"', $artist, $album)
            : sprintf('artist:"%s"', $artist);

        $response = Http::get('https://api.deezer.com/search/album', [
            'q' => $query,
            'limit' => 5,
        ]);

        if (! $response->ok()) {
            return null;
        }

        $want = $this->normalise($artist);

        foreach ($response->json('data', []) as $result) {
            $got = $this->normalise((string) ($result['artist']['name'] ?? ''));

            if ($got === '' || ! $this->artistMatches($got, $want)) {
                continue;
            }

            $cover = $result['cover_xl'] ?? $result['cover_big'] ?? $result['cover_medium'] ?? null;

            if (filled($cover)) {
                return $cover;
            }
        }

        return null;
    }

    // ----------------------------------------------------------------- store

    /**
     * Downloads an image and returns its stored public-disk path.
     *
     * One file per unique source URL (hashed), so an album shared across many
     * tracks — or the same cover reused across albums — is written once and
     * reused. Returns null if the download fails.
     */
    private function store(string $url): ?string
    {
        $name = hash('xxh128', $url);
        $existing = $this->existingFor($name);

        if ($existing !== null) {
            return $existing;
        }

        $this->throttle();

        try {
            $response = Http::timeout(20)->get($url);
        } catch (\Throwable) {
            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        $ext = str_contains((string) $response->header('Content-Type'), 'png') ? 'png' : 'jpg';
        $path = self::COVER_DIR.'/'.$name.'.'.$ext;

        Storage::disk('public')->put($path, $response->body());

        return $path;
    }

    /** An already-stored file for this hash (jpg or png), if present. */
    private function existingFor(string $name): ?string
    {
        foreach (['jpg', 'png'] as $ext) {
            $path = self::COVER_DIR.'/'.$name.'.'.$ext;
            if (Storage::disk('public')->exists($path)) {
                return $path;
            }
        }

        return null;
    }

    // ---------------------------------------------------------------- helpers

    private function cacheKey(string $artist, ?string $album): string
    {
        return $this->normalise($artist).'|'.$this->normalise((string) $album);
    }

    private function artistMatches(string $got, string $want): bool
    {
        return $got === $want || str_contains($got, $want) || str_contains($want, $got);
    }

    /** Lower-cased, "the "-stripped, punctuation-free — for comparing artists. */
    private function normalise(string $value): string
    {
        $value = mb_strtolower(trim($value));
        $value = preg_replace('/^the\s+/', '', $value) ?? $value;

        return preg_replace('/[^a-z0-9]+/', '', $value) ?? $value;
    }

    private function throttle(): void
    {
        if ($this->throttleMs > 0) {
            usleep($this->throttleMs * 1000);
        }
    }
}
