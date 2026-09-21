<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace SoundChex\CoverArtArchive;

use App\Plugins\Contracts\CoverSource;
use Illuminate\Support\Facades\Http;

/**
 * Looks up an album cover on the Cover Art Archive via MusicBrainz (S-264, #280).
 *
 * Two hops, both keyless: find the release group on MusicBrainz, then ask the
 * Cover Art Archive for that group's front cover. Validates the release group's
 * artist against the one asked for, so a loose title match does not return the
 * wrong cover — a wrong cover being worse than none.
 *
 * A worked example: a real cover source in ~40 lines. Any failure returns null,
 * never throws, so the fetcher simply moves on.
 */
class CoverArtArchiveSource implements CoverSource
{
    private const MB = 'https://musicbrainz.org/ws/2';

    private const CAA = 'https://coverartarchive.org';

    public function name(): string
    {
        return 'Cover Art Archive';
    }

    public function priority(): int
    {
        return 50;
    }

    public function coverUrlFor(string $artist, ?string $album): ?string
    {
        if ($album === null || $album === '') {
            return null;
        }

        try {
            $mbid = $this->releaseGroupId($artist, $album);

            if ($mbid === null) {
                return null;
            }

            // The archive redirects to the actual image; a 200 with a front
            // cover means it has one. Ask for the release group's front image.
            $response = Http::timeout(10)->get(self::CAA."/release-group/{$mbid}/front");

            return $response->successful() ? (string) $response->effectiveUri() : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * The MusicBrainz release-group id for this artist+album, verified against
     * the artist, or null.
     */
    private function releaseGroupId(string $artist, string $album): ?string
    {
        $query = sprintf('releasegroup:"%s" AND artist:"%s"', $this->escape($album), $this->escape($artist));

        $response = Http::timeout(10)
            ->withHeaders(['User-Agent' => 'SoundChex-CoverArtArchive-Plugin/1.0'])
            ->acceptJson()
            ->get(self::MB.'/release-group', ['query' => $query, 'fmt' => 'json', 'limit' => 5]);

        if (! $response->successful()) {
            return null;
        }

        foreach ($response->json('release-groups') ?? [] as $group) {
            $credited = $group['artist-credit'][0]['name'] ?? '';

            // Only accept a group whose artist actually matches the ask.
            if ($this->normalise($credited) === $this->normalise($artist)) {
                return $group['id'] ?? null;
            }
        }

        return null;
    }

    private function escape(string $value): string
    {
        return str_replace(['"', '\\'], ['', ''], $value);
    }

    private function normalise(string $value): string
    {
        return mb_strtolower(trim($value));
    }
}
