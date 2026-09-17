<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Services\Metadata\Sources\Concerns;

use App\Enums\MatchConfidence;
use App\Enums\MediaTagSource;
use App\Models\MediaItem;
use App\Models\Person;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Shared TMDB behaviour for the movie and show sources.
 *
 * The two endpoints differ in shape but not in mechanics: same auth, same
 * image CDN, same cast/crew structure. Keeping that here means a fix to
 * artwork sizing or credit handling lands for both at once.
 */
trait TalksToTmdb
{
    private const BASE = 'https://api.themoviedb.org/3';

    /** TMDB serves images from a separate CDN with size-prefixed paths. */
    private const IMAGE_BASE = 'https://image.tmdb.org/t/p';

    /**
     * How many cast members to store. Full credits run to hundreds of names,
     * which is noise on a detail page.
     */
    private const CAST_LIMIT = 12;

    public function requiredSettings(): array
    {
        return ['tmdb_api_key' => 'TMDB API Key (v3 auth)'];
    }

    private function apiKey(): ?string
    {
        return $this->settings->get('tmdb_api_key');
    }

    /**
     * @param array<string, mixed> $query
     */
    private function request(string $path, array $query = []): ?Response
    {
        $key = $this->apiKey();

        if (blank($key)) {
            return null;
        }

        $response = Http::acceptJson()
            ->timeout(10)
            ->retry(2, 500, throw: false)
            ->get(self::BASE . $path, $query + ['api_key' => $key]);

        return $response->successful() ? $response : null;
    }

    /**
     * Builds a full image URL, or null when TMDB has no artwork for the field.
     *
     * `w780` for backdrops and `w500` for posters: large enough to look sharp
     * on a hero banner without pulling multi-megabyte originals.
     */
    private function imageUrl(?string $path, string $size = 'w500'): ?string
    {
        return filled($path)
            ? self::IMAGE_BASE . '/' . $size . '/' . ltrim($path, '/')
            : null;
    }

    /**
     * Writes only fields that are still empty, so file tags and manual edits
     * (both of which outrank an API) survive.
     *
     * @param array<string, mixed> $values
     */
    private function fillBlank(?object $meta, array $values): void
    {
        if ($meta === null) {
            return;
        }

        $dirty = false;

        foreach ($values as $field => $value) {
            if (blank($meta->{$field}) && filled($value)) {
                $meta->{$field} = $value;
                $dirty = true;
            }
        }

        if ($dirty) {
            $meta->saveQuietly();
        }
    }

    /**
     * @param array<int, array<string, mixed>> $genres
     */
    private function writeGenreTags(MediaItem $item, array $genres): void
    {
        $aliases = config('metadata_rules.genre_aliases', []);

        foreach (array_slice($genres, 0, 4) as $genre) {
            $name = $genre['name'] ?? null;

            if (blank($name)) {
                continue;
            }

            $canonical = $aliases[strtolower($name)] ?? $name;

            $exists = $item->tags()
                ->where('type', 'genre')
                ->where('value', $canonical)
                ->exists();

            if ($exists) {
                continue;
            }

            $item->tags()->create([
                'type'   => 'genre',
                'value'  => $canonical,
                'source' => MediaTagSource::Api,
            ]);
        }
    }

    /**
     * Stores cast and the directing/creating crew.
     *
     * People are shared across the catalog and keyed by TMDB id, so the same
     * actor in three films is one row.
     *
     * @param array<string, mixed> $credits
     * @param array<int, string> $crewJobs Job titles worth keeping.
     */
    private function writeCredits(MediaItem $item, array $credits, array $crewJobs): void
    {
        // Re-enriching shouldn't stack duplicate credits.
        if ($item->people()->exists()) {
            return;
        }

        $sort = 0;

        foreach (array_slice($credits['cast'] ?? [], 0, self::CAST_LIMIT) as $member) {
            $person = $this->resolvePerson($member);

            if ($person === null) {
                continue;
            }

            $item->people()->attach($person->id, [
                'role'       => 'actor',
                'character'  => $member['character'] ?? null,
                'sort_order' => $sort++,
            ]);
        }

        foreach ($credits['crew'] ?? [] as $member) {
            $job = $member['job'] ?? '';

            if (! in_array($job, $crewJobs, true)) {
                continue;
            }

            $person = $this->resolvePerson($member);

            if ($person === null) {
                continue;
            }

            $item->people()->attach($person->id, [
                'role'       => strtolower($job),
                'sort_order' => $sort++,
            ]);
        }
    }

    /**
     * @param array<string, mixed> $member
     */
    private function resolvePerson(array $member): ?Person
    {
        $name = $member['name'] ?? null;

        if (blank($name)) {
            return null;
        }

        $person = Person::firstOrCreate(
            ['tmdb_id' => $member['id'] ?? null],
            ['name' => $name],
        );

        if (blank($person->headshot_url) && filled($member['profile_path'] ?? null)) {
            $person->headshot_url = $this->imageUrl($member['profile_path'], 'w185');
            $person->saveQuietly();
        }

        return $person;
    }

    /**
     * Records how certain this match was.
     *
     * The organizer renames and relocates files from resolved metadata, so a
     * wrong match refiles a user's film under the wrong name. Without this the
     * item stays at `none` and is never organized at all — safe, but it means
     * nothing video ever gets filed.
     *
     * A TMDB id the item already carried is unambiguous. Otherwise the match
     * came from a title search, and only a verbatim title counts as exact —
     * anything reached by splitting off a year or by popularity ranking is
     * likely right but not certain enough to move a file over.
     */
    private function recordConfidence(MediaItem $item, array $payload, bool $matchedById): void
    {
        // Never downgrade: an earlier source may already have pinned this.
        if ($item->match_confidence === MatchConfidence::Exact) {
            return;
        }

        $canonical = $payload['title'] ?? $payload['name'] ?? '';

        $exact = $matchedById
            || strcasecmp(trim($canonical), trim((string) $item->title)) === 0;

        $item->forceFill([
            'match_confidence' => $exact ? MatchConfidence::Exact : MatchConfidence::Fuzzy,
            'matched_by' => $this->name(),
        ])->saveQuietly();
    }

    /**
     * Sets the item's artwork if it doesn't have any yet.
     *
     * @param array<string, mixed> $payload
     */
    private function writeArtwork(MediaItem $item, array $payload): void
    {
        if (filled($item->cover_image_url)) {
            return;
        }

        $poster = $this->imageUrl($payload['poster_path'] ?? null, 'w500')
            ?? $this->imageUrl($payload['backdrop_path'] ?? null, 'w780');

        if ($poster === null) {
            return;
        }

        $item->cover_image_url = $poster;
        $item->saveQuietly();
    }

    /** Dates arrive as "2017-10-04"; the catalog stores the year. */
    private function extractYear(?string $date): ?int
    {
        if (blank($date) || ! preg_match('/(\d{4})/', $date, $m)) {
            return null;
        }

        return (int) $m[1];
    }
}
