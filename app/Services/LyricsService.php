<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Services;

use App\Models\MediaItem;
use getID3;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Finds and caches lyrics for a track — plain and time-synced (LRC).
 *
 * Sources are tried in a deliberate order, most trustworthy first (S-300):
 *
 *  1. **Embedded** — lyrics that ship with the file itself: a `.lrc` sidecar
 *     next to the track (the de-facto synced-lyrics format), then the file's own
 *     tags (id3 SYLT/USLT, Vorbis `LYRICS`). These are the user's own data, need
 *     no network, and honour the "your own library" ethos.
 *  2. **Provider** — LRCLIB (free, no key), which returns both plain and synced
 *     lyrics, matched on the metadata a scanned library already has.
 *  3. **Plain fallback** — when no timing is available anywhere, the unsynced
 *     words still show, just without the scroll-highlight.
 *
 * The result is stored on the track's music metadata so the lookup runs once,
 * not on every play. A track with genuinely nothing is remembered as such (via
 * `lyrics_checked_at`) so a miss is not re-fetched every open.
 */
class LyricsService
{
    /** Re-check a track that had no lyrics no more than this often. */
    private const RECHECK_AFTER_DAYS = 30;

    /**
     * The plain-text lyrics for an item, fetching and caching on a miss.
     *
     * Kept for callers that only want the words; {@see lyricsPayloadFor()} gives
     * both plain and synced.
     */
    public function lyricsFor(MediaItem $item): ?string
    {
        return $this->lyricsPayloadFor($item)['plain'];
    }

    /**
     * Both the plain and the time-synced (LRC) lyrics for an item, fetching and
     * caching on a miss.
     *
     * Returns `['plain' => ?string, 'synced' => ?string]`; either may be null.
     * `synced` is LRC text — lines prefixed with `[mm:ss.xx]` timestamps — which
     * the player uses to highlight the current line; `plain` is the words alone.
     * Never throws: a lyric lookup failing must not break playback.
     *
     * @return array{plain: ?string, synced: ?string}
     */
    public function lyricsPayloadFor(MediaItem $item): array
    {
        if ($item->type->value !== 'music') {
            return ['plain' => null, 'synced' => null];
        }

        $meta = $item->musicMetadata;

        if ($meta === null) {
            return ['plain' => null, 'synced' => null];
        }

        // A stored answer wins — including a stored empty (checked, none found)
        // still recent enough not to re-ask.
        if (filled($meta->lyrics) || filled($meta->lyrics_synced)) {
            return ['plain' => $meta->lyrics, 'synced' => $meta->lyrics_synced];
        }

        if ($meta->lyrics_checked_at !== null
            && $meta->lyrics_checked_at->gt(now()->subDays(self::RECHECK_AFTER_DAYS))) {
            return ['plain' => null, 'synced' => null];
        }

        return $this->fetchAndStore($item, $meta);
    }

    /**
     * @return array{plain: ?string, synced: ?string}
     */
    private function fetchAndStore(MediaItem $item, $meta): array
    {
        // 1. Embedded — the file's own lyrics. No network, always tried first.
        $found = $this->fromEmbedded($item);

        // 2. Provider — only when the file carries nothing (or no timing).
        if (blank($found['plain']) && blank($found['synced'])) {
            $artist = $meta->artist ?? $meta->primary_artist;
            $title = $item->title;

            if (blank($artist) || blank($title)) {
                // Nothing embedded and nothing to match a provider on.
                $meta->forceFill(['lyrics_checked_at' => now()])->save();

                return ['plain' => null, 'synced' => null];
            }

            try {
                $found = $this->fromLrclib($artist, $title, $meta->album, $meta->duration_ms);
            } catch (\Throwable $e) {
                // Logged, not thrown: a provider being down is not a playback
                // error, and checked-at is deliberately left unset so it retries.
                Log::warning('lyrics:lookup-failed', [
                    'item' => $item->id,
                    'reason' => $e->getMessage(),
                ]);

                return ['plain' => null, 'synced' => null];
            }
        }

        $meta->forceFill([
            'lyrics' => $found['plain'] ?? null,
            'lyrics_synced' => $found['synced'] ?? null,
            'lyrics_checked_at' => now(),
        ])->save();

        return ['plain' => $found['plain'] ?? null, 'synced' => $found['synced'] ?? null];
    }

    /**
     * Lyrics that ship with the file: a `.lrc` sidecar beside the track (synced),
     * then the file's own tags (id3 SYLT/USLT, Vorbis `LYRICS`).
     *
     * @return array{plain: ?string, synced: ?string}
     */
    private function fromEmbedded(MediaItem $item): array
    {
        $path = $item->absoluteFilePath();

        if ($path === null || ! is_file($path)) {
            return ['plain' => null, 'synced' => null];
        }

        // A .lrc sidecar next to the file — the same basename, .lrc extension —
        // is the common way synced lyrics travel, and it is authoritative.
        $sidecar = preg_replace('/\.[^.\/]+$/', '.lrc', $path);

        if ($sidecar !== null && $sidecar !== $path && is_file($sidecar)) {
            $lrc = trim((string) @file_get_contents($sidecar));

            if ($lrc !== '') {
                return [
                    'synced' => $this->looksSynced($lrc) ? $lrc : null,
                    'plain' => $this->looksSynced($lrc) ? $this->stripTimestamps($lrc) : $lrc,
                ];
            }
        }

        // Embedded tags. getID3 surfaces both a synced list and unsynced text.
        try {
            $info = (new getID3)->analyze($path);
        } catch (\Throwable $e) {
            return ['plain' => null, 'synced' => null];
        }

        $synced = $this->syncedFromTags($info);
        $plain = $this->plainFromTags($info);

        return [
            'synced' => $synced,
            'plain' => $plain ?? ($synced !== null ? $this->stripTimestamps($synced) : null),
        ];
    }

    /**
     * A synced-lyrics LRC string from getID3's parse, if the file carries one.
     *
     * id3v2 SYLT is reported as a structured list of {timestamp(ms), text}; a
     * `.lrc` stored in an unsynced field (USLT/LYRICS) already reads as LRC.
     */
    private function syncedFromTags(array $info): ?string
    {
        // id3v2 SYLT — structured synced lyrics.
        $sylt = $info['id3v2']['SYLT'][0]['data'] ?? null;

        if (is_array($sylt) && $sylt !== []) {
            $lines = [];
            foreach ($sylt as $entry) {
                $ms = $entry['timestamp'] ?? null;
                $text = trim((string) ($entry['data'] ?? $entry['lyric'] ?? ''));
                if ($ms !== null && $text !== '') {
                    $lines[] = $this->lrcTimestamp((int) $ms).' '.$text;
                }
            }
            if ($lines !== []) {
                return implode("\n", $lines);
            }
        }

        // An LRC hiding in an unsynced field (some taggers store it in USLT or a
        // Vorbis LYRICS comment).
        foreach ($this->unsyncedCandidates($info) as $candidate) {
            if ($this->looksSynced($candidate)) {
                return trim($candidate);
            }
        }

        return null;
    }

    private function plainFromTags(array $info): ?string
    {
        foreach ($this->unsyncedCandidates($info) as $candidate) {
            $candidate = trim($candidate);
            if ($candidate === '') {
                continue;
            }

            return $this->looksSynced($candidate) ? $this->stripTimestamps($candidate) : $candidate;
        }

        return null;
    }

    /**
     * Raw lyric strings from every tag field getID3 might put them in.
     *
     * @return array<int, string>
     */
    private function unsyncedCandidates(array $info): array
    {
        $out = [];

        // id3v2 USLT (unsynchronised lyrics).
        foreach ($info['id3v2']['USLT'] ?? [] as $uslt) {
            if (filled($uslt['data'] ?? null)) {
                $out[] = (string) $uslt['data'];
            }
        }

        // Flattened comment tags across formats (Vorbis LYRICS, etc.).
        $tags = $info['tags'] ?? [];
        foreach ($tags as $format => $fields) {
            foreach (['lyrics', 'unsynced lyrics', 'unsyncedlyrics', 'lyrics-xxx'] as $key) {
                foreach ((array) ($fields[$key] ?? []) as $value) {
                    if (filled($value)) {
                        $out[] = (string) $value;
                    }
                }
            }
        }

        return $out;
    }

    /** An `[mm:ss.xx]` timestamp for a millisecond offset. */
    private function lrcTimestamp(int $ms): string
    {
        $totalCentis = intdiv($ms, 10);
        $centis = $totalCentis % 100;
        $totalSeconds = intdiv($totalCentis, 100);
        $seconds = $totalSeconds % 60;
        $minutes = intdiv($totalSeconds, 60);

        return sprintf('[%02d:%02d.%02d]', $minutes, $seconds, $centis);
    }

    /** Whether a lyric string carries `[mm:ss]` timing (i.e. is LRC/synced). */
    private function looksSynced(string $text): bool
    {
        return preg_match('/^\s*\[\d{1,2}:\d{2}(?:[.:]\d{1,3})?\]/m', $text) === 1;
    }

    /** The words of an LRC, with the leading `[mm:ss.xx]` timestamps removed. */
    private function stripTimestamps(string $lrc): string
    {
        $lines = [];
        foreach (preg_split('/\r\n|\r|\n/', $lrc) as $line) {
            // Drop leading timestamps and LRC id tags ([ar:], [ti:], [length:]).
            $stripped = preg_replace('/^\s*(\[[^\]]*\]\s*)+/', '', $line);
            $stripped = trim((string) $stripped);
            if ($stripped !== '') {
                $lines[] = $stripped;
            }
        }

        return implode("\n", $lines);
    }

    /**
     * LRCLIB (https://lrclib.net) — an open, key-less lyrics database.
     *
     * Matches on artist, title, album and track length. The duration match is
     * what stops a same-named cover or remix returning the wrong words; LRCLIB
     * itself allows a couple of seconds of slack, so an exact `duration` is sent
     * when known and the search endpoint (looser) is the fallback.
     *
     * @return array{plain: ?string, synced: ?string}
     */
    private function fromLrclib(string $artist, string $title, ?string $album, ?int $durationMs): array
    {
        $params = [
            'artist_name' => $artist,
            'track_name' => $title,
        ];

        if (filled($album)) {
            $params['album_name'] = $album;
        }

        if ($durationMs !== null && $durationMs > 0) {
            $params['duration'] = (int) round($durationMs / 1000);
        }

        // The `get` endpoint wants an exact match; on a miss (404) fall back to
        // `search`, which is fuzzier, and take the first hit.
        $response = Http::timeout(8)
            ->withHeaders(['User-Agent' => 'SoundChex (self-hosted media server)'])
            ->get('https://lrclib.net/api/get', $params);

        if ($response->status() === 404) {
            return $this->searchLrclib($artist, $title);
        }

        $response->throw();
        $body = $response->json();

        return [
            'plain' => $body['plainLyrics'] ?? null,
            'synced' => $body['syncedLyrics'] ?? null,
        ];
    }

    /** @return array{plain: ?string, synced: ?string} */
    private function searchLrclib(string $artist, string $title): array
    {
        $response = Http::timeout(8)
            ->withHeaders(['User-Agent' => 'SoundChex (self-hosted media server)'])
            ->get('https://lrclib.net/api/search', [
                'artist_name' => $artist,
                'track_name' => $title,
            ]);

        $response->throw();
        $first = $response->json()[0] ?? null;

        if ($first === null) {
            return ['plain' => null, 'synced' => null];
        }

        return [
            'plain' => $first['plainLyrics'] ?? null,
            'synced' => $first['syncedLyrics'] ?? null,
        ];
    }
}
