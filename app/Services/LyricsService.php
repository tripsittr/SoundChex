<?php

namespace App\Services;

use App\Models\MediaItem;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Finds and caches lyrics for a track.
 *
 * Lyrics are looked up from a provider once and stored on the track's music
 * metadata, so the same request is not made on every play. The default provider
 * is LRCLIB — free, no API key, and it matches on the metadata a scanned library
 * already has (artist, title, album, duration). Genius and Musixmatch are
 * anticipated by the integrations page and can be added as further providers
 * behind this same interface; LRCLIB is what works with nothing configured.
 *
 * A track with genuinely no lyrics is remembered as such (via
 * `lyrics_checked_at`) so a miss is not re-fetched on every open — but a caller
 * can force a fresh lookup.
 */
class LyricsService
{
    /** Re-check a track that had no lyrics no more than this often. */
    private const RECHECK_AFTER_DAYS = 30;

    /**
     * The plain-text lyrics for an item, fetching and caching on a miss.
     *
     * Returns null for anything that is not music, has no artist/title to match
     * on, or that the provider does not have. Never throws to the caller: a
     * lyric lookup failing must not break playback.
     */
    public function lyricsFor(MediaItem $item): ?string
    {
        if ($item->type->value !== 'music') {
            return null;
        }

        $meta = $item->musicMetadata;

        if ($meta === null) {
            return null;
        }

        // A stored answer wins — including a stored empty (checked, none found)
        // that is still recent enough not to re-ask.
        if (filled($meta->lyrics)) {
            return $meta->lyrics;
        }

        if ($meta->lyrics_checked_at !== null
            && $meta->lyrics_checked_at->gt(now()->subDays(self::RECHECK_AFTER_DAYS))) {
            return null;
        }

        return $this->fetchAndStore($item, $meta);
    }

    private function fetchAndStore(MediaItem $item, $meta): ?string
    {
        $artist = $meta->artist ?? $meta->primary_artist;
        $title = $item->title;

        // Nothing to match on.
        if (blank($artist) || blank($title)) {
            $meta->forceFill(['lyrics_checked_at' => now()])->save();

            return null;
        }

        try {
            $found = $this->fromLrclib($artist, $title, $meta->album, $meta->duration_ms);
        } catch (\Throwable $e) {
            // Logged, not thrown: a provider being down is not a playback error,
            // and the checked-at stamp is deliberately not set so it retries.
            Log::warning('lyrics:lookup-failed', [
                'item' => $item->id,
                'reason' => $e->getMessage(),
            ]);

            return null;
        }

        $meta->forceFill([
            'lyrics' => $found['plain'] ?? null,
            'lyrics_synced' => $found['synced'] ?? null,
            'lyrics_checked_at' => now(),
        ])->save();

        return $found['plain'] ?? null;
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
