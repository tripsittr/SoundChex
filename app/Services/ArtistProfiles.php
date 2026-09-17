<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Services;

use App\Models\Person;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Fills in who an artist is.
 *
 * `people` has carried music rows since credits were written, but only a name
 * and an id — enough to group tracks and nothing worth visiting a page for.
 *
 * Everything here comes from MusicBrainz and Wikipedia, neither of which needs
 * an API key. MusicBrainz asks for one request a second and enforces it, so
 * this is built to be called per artist by a command that paces itself rather
 * than in a loop over a library.
 */
class ArtistProfiles
{
    private const MUSICBRAINZ = 'https://musicbrainz.org/ws/2';

    /** How long a profile is trusted before it is worth looking again. */
    private const FRESH_FOR_DAYS = 90;

    /**
     * Looks an artist up and stores what comes back.
     *
     * @return bool whether anything was written
     */
    public function fetch(Person $person, bool $force = false): bool
    {
        if (! $force && ! $this->isStale($person)) {
            return false;
        }

        $mbid = $person->musicbrainz_artist_id ?: $this->findId($person->name);

        if ($mbid === null) {
            // Recorded so a fruitless search is not repeated on every run. Most
            // of a self-hosted library will never match, and that is fine — it
            // just should not cost a request each time.
            $person->forceFill(['profile_synced_at' => now()])->saveQuietly();

            return false;
        }

        $artist = $this->lookup($mbid);

        if ($artist === null) {
            $person->forceFill(['profile_synced_at' => now()])->saveQuietly();

            return false;
        }

        $person->forceFill([
            'musicbrainz_artist_id' => $mbid,
            'artist_type' => $artist['type'] ?? null,
            'country' => $artist['country'] ?? null,
            'began' => $artist['life-span']['begin'] ?? null,
            'ended' => $artist['life-span']['end'] ?? null,
            'disambiguation' => $artist['disambiguation'] ?: null,
            'biography' => $this->biography($artist),
            'profile_synced_at' => now(),
        ])->saveQuietly();

        $this->fetchImage($person, $artist);

        return true;
    }

    /** Whether this profile is old enough to be worth looking up again. */
    public function isStale(Person $person): bool
    {
        return $person->profile_synced_at === null
            || $person->profile_synced_at->lt(now()->subDays(self::FRESH_FOR_DAYS));
    }

    /**
     * The MusicBrainz id for a name, when the match is unambiguous.
     *
     * Deliberately strict. A wrong artist attached to a page is worse than an
     * empty one — it puts someone else's photograph and biography on your
     * record — so anything short of an exact, confident name match is refused.
     */
    private function findId(string $name): ?string
    {
        $response = $this->request('/artist', [
            'query' => 'artist:"' . str_replace('"', '', $name) . '"',
            'limit' => 3,
        ]);

        if ($response === null) {
            return null;
        }

        $artists = $response->json('artists') ?? [];

        foreach ($artists as $artist) {
            $exact = mb_strtolower($artist['name'] ?? '') === mb_strtolower($name);

            // MusicBrainz scores 0–100. Below 90 on an exact name usually means
            // several artists share it, which is precisely when guessing is
            // worst.
            if ($exact && ($artist['score'] ?? 0) >= 90) {
                return $artist['id'] ?? null;
            }
        }

        return null;
    }

    /** @return array<string, mixed>|null */
    private function lookup(string $mbid): ?array
    {
        $response = $this->request('/artist/' . $mbid, ['inc' => 'url-rels']);

        return $response?->json();
    }

    /**
     * A short biography, from Wikipedia by way of the artist's Wikidata link.
     *
     * MusicBrainz holds no prose of its own — what it holds is the link, which
     * is the part that is hard to find reliably.
     */
    private function biography(array $artist): ?string
    {
        $title = $this->wikipediaTitle($artist);

        if ($title === null) {
            return null;
        }

        try {
            $response = Http::withHeaders(['User-Agent' => $this->agent()])
                ->acceptJson()
                ->timeout(10)
                ->get('https://en.wikipedia.org/api/rest_v1/page/summary/' . rawurlencode($title));

            if (! $response->successful()) {
                return null;
            }

            $extract = trim((string) $response->json('extract'));

            return $extract === '' ? null : $extract;
        } catch (\Throwable $e) {
            Log::warning('Could not read an artist biography', [
                'title' => $title,
                'reason' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * The Wikipedia page title for this artist.
     *
     * A direct `wikipedia` relation is the easy case and often absent —
     * MusicBrainz has largely moved to linking Wikidata instead, which is why
     * looking only for the direct one found nothing for an artist who plainly
     * has a page.
     */
    private function wikipediaTitle(array $artist): ?string
    {
        $wikidata = null;

        foreach ($artist['relations'] ?? [] as $relation) {
            $url = $relation['url']['resource'] ?? '';

            if (($relation['type'] ?? '') === 'wikipedia' && preg_match('#/wiki/(.+)$#', $url, $matches)) {
                return rawurldecode($matches[1]);
            }

            if (($relation['type'] ?? '') === 'wikidata' && preg_match('#/(Q\d+)$#', $url, $matches)) {
                $wikidata = $matches[1];
            }
        }

        return $wikidata === null ? null : $this->titleFromWikidata($wikidata);
    }

    /** The English Wikipedia title a Wikidata entity points at. */
    private function titleFromWikidata(string $entity): ?string
    {
        try {
            $response = Http::withHeaders(['User-Agent' => $this->agent()])
                ->acceptJson()
                ->timeout(10)
                ->get('https://www.wikidata.org/w/api.php', [
                    'action' => 'wbgetentities',
                    'ids' => $entity,
                    'props' => 'sitelinks',
                    'sitefilter' => 'enwiki',
                    'format' => 'json',
                ]);

            if (! $response->successful()) {
                return null;
            }

            return $response->json("entities.{$entity}.sitelinks.enwiki.title");
        } catch (\Throwable $e) {
            Log::warning('Could not resolve a Wikidata entity', [
                'entity' => $entity,
                'reason' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Stores the artist's image locally rather than hotlinking it.
     *
     * A hotlinked Wikimedia URL breaks when the file is renamed, loads slowly
     * from a phone, and leaks every page view to a third party. Copying it once
     * costs a few hundred kilobytes.
     */
    private function fetchImage(Person $person, array $artist): void
    {
        $source = $this->imageUrl($artist);

        if ($source === null || $source === $person->image_source) {
            return;
        }

        try {
            $response = Http::withHeaders(['User-Agent' => $this->agent()])
                ->timeout(20)
                ->get($source);

            if (! $response->successful()) {
                return;
            }

            $extension = str_contains($response->header('Content-Type'), 'png') ? 'png' : 'jpg';
            $path = 'artists/' . $person->id . '.' . $extension;

            Storage::disk('public')->put($path, $response->body());

            $person->forceFill([
                'headshot_url' => Storage::disk('public')->url($path),
                'image_source' => $source,
            ])->saveQuietly();
        } catch (\Throwable $e) {
            // A missing picture is not a reason to lose the biography that was
            // already saved.
            Log::warning('Could not fetch an artist image', [
                'person' => $person->id,
                'source' => $source,
                'reason' => $e->getMessage(),
            ]);
        }
    }

    /**
     * A direct image URL from the Wikimedia Commons relation.
     *
     * The relation points at a file *page*, not the file, so it goes through
     * Special:FilePath — which redirects to whatever the current file is and
     * survives the file being renamed.
     */
    private function imageUrl(array $artist): ?string
    {
        foreach ($artist['relations'] ?? [] as $relation) {
            if (($relation['type'] ?? '') !== 'image') {
                continue;
            }

            $url = $relation['url']['resource'] ?? '';

            if (preg_match('#commons\.wikimedia\.org/wiki/(?:File|Special:FilePath)[:/](.+)$#', $url, $matches)) {
                return 'https://commons.wikimedia.org/wiki/Special:FilePath/'
                    . rawurlencode(rawurldecode($matches[1]))
                    . '?width=600';
            }
        }

        return null;
    }

    /** @param array<string, mixed> $query */
    private function request(string $path, array $query): ?Response
    {
        try {
            $response = Http::withHeaders(['User-Agent' => $this->agent()])
                ->acceptJson()
                ->timeout(10)
                ->retry(2, 1000, throw: false)
                ->get(self::MUSICBRAINZ . $path, $query + ['fmt' => 'json']);

            return $response->successful() ? $response : null;
        } catch (\Throwable $e) {
            Log::warning('An artist lookup failed', [
                'path' => $path,
                'reason' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /** MusicBrainz blocks clients without a descriptive User-Agent. */
    private function agent(): string
    {
        return sprintf(
            '%s/1.0 (%s)',
            config('app.name', 'SoundChex'),
            config('app.url', 'https://github.com/tripsittr/SoundChex'),
        );
    }
}
