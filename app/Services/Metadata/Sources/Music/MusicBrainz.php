<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Services\Metadata\Sources\Music;

use App\Enums\MatchConfidence;
use App\Enums\MediaItemType;
use App\Enums\MediaTagSource;
use App\Enums\ProcessingStatus;
use App\Models\MediaItem;
use App\Services\Metadata\Contracts\MetadataSource;
use App\Services\MusicCredits;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Layer 3 — MusicBrainz. Canonical recording/release IDs, labels, and ISRC.
 *
 * No API key. MusicBrainz requires a descriptive User-Agent and rate-limits
 * anonymous clients to roughly one request per second.
 *
 * Lookup order, most reliable first:
 *   1. A recording MBID we already hold (from file tags or AcoustID)
 *   2. ISRC — an exact identifier
 *   3. Artist + title search
 */
class MusicBrainz implements MetadataSource
{
    private const BASE = 'https://musicbrainz.org/ws/2';

    public function name(): string
    {
        return 'MusicBrainz';
    }

    public function priority(): int
    {
        return 3;
    }

    public function requiredSettings(): array
    {
        return [];
    } // no key needed

    public function supports(MediaItem $item): bool
    {
        if ($item->type !== MediaItemType::Music) {
            return false;
        }

        $meta = $item->musicMetadata;

        // Needs at least one identifier or a searchable artist/title pair.
        return filled($meta?->musicbrainz_recording_id)
            || filled($meta?->isrc)
            || (filled($meta?->artist) && filled($item->title));
    }

    public function enrich(MediaItem $item): void
    {
        // How the recording was found, so the confidence reflects it: an id or
        // ISRC is unambiguous (Exact); a text search is likely-right (Fuzzy).
        $matchedByIdentifier = filled($item->musicMetadata?->musicbrainz_recording_id)
            || filled($item->musicMetadata?->isrc);

        $recording = $this->resolveRecording($item);

        if (empty($recording)) {
            return;
        }

        $this->writeRecordingFields($item, $recording);
        $this->writeReleaseFields($item, $recording);
        $this->writeCredits($item, $recording);
        $this->writeGenreTags($item, $recording);
        $this->recordConfidence($item, $matchedByIdentifier);
        // Review flagging stays: an uncertain match is still surfaced for the
        // user even though it now carries a confidence.
        $this->flagIfUncertain($item, $recording);
    }

    /**
     * Records that this source identified the track, so the library no longer
     * reads the whole music catalogue as unmatched.
     *
     * Exact when reached by a MusicBrainz recording id or ISRC — those are
     * unambiguous — otherwise Fuzzy, since a text search is likely right but not
     * certain. Never downgrades a match an earlier source already pinned as
     * Exact. This is orthogonal to flagIfUncertain(): a Fuzzy match can also be
     * flagged for review.
     */
    private function recordConfidence(MediaItem $item, bool $matchedByIdentifier): void
    {
        if ($item->match_confidence === MatchConfidence::Exact) {
            return;
        }

        $item->forceFill([
            'match_confidence' => $matchedByIdentifier ? MatchConfidence::Exact : MatchConfidence::Fuzzy,
            'matched_by' => $this->name(),
        ])->saveQuietly();
    }

    /**
     * Marks the item for human review when the match looks shaky.
     *
     * MusicBrainz has no notion of "the famous version" — for heavily
     * compiled artists the studio original can sit outside the candidates
     * entirely. Rather than silently present a compilation as fact, surface
     * it in the Needs Review tab so the user can correct it.
     *
     * @param  array<string, mixed>  $recording
     */
    private function flagIfUncertain(MediaItem $item, array $recording): void
    {
        $release = $this->preferredRelease(
            $recording['releases'] ?? [],
            $recording['first-release-date'] ?? null,
        );

        $undated = blank($this->extractYear($this->releaseDate($release ?? [])));
        $notStudio = ! empty($release) && ! $this->isStudioAlbum($release);

        if (! $undated && ! $notStudio) {
            return;
        }

        // Don't downgrade an item a later source may still resolve cleanly, and
        // never overwrite a terminal state the user has already acted on.
        if ($item->processing_status === ProcessingStatus::NeedsReview) {
            return;
        }

        $item->processing_status = ProcessingStatus::NeedsReview;
        $item->saveQuietly();
    }

    /**
     * @return array<string, mixed>|null
     */
    private function resolveRecording(MediaItem $item): ?array
    {
        $meta = $item->musicMetadata;

        if (filled($meta?->musicbrainz_recording_id)) {
            return $this->lookupById($meta->musicbrainz_recording_id);
        }

        if (filled($meta?->isrc)) {
            $byIsrc = $this->searchRecordings('isrc:'.$meta->isrc);

            if (! empty($byIsrc)) {
                return $byIsrc;
            }
        }

        if (filled($meta?->artist) && filled($item->title)) {
            $title = $this->escapeLucene($item->title);
            $artist = $this->escapeLucene($meta->artist);

            // Constrain to official studio albums server-side. Without this the
            // result set is dominated by bootlegs and live recordings that tie
            // with the original on text relevance.
            $canonical = $this->searchRecordings(sprintf(
                'recording:"%s" AND artist:"%s" AND status:official AND primarytype:album AND NOT secondarytype:live AND NOT secondarytype:compilation',
                $title,
                $artist,
            ));

            if (! empty($canonical)) {
                return $canonical;
            }

            // Not everything lives on a studio album (singles, EPs, DJ mixes),
            // so fall back to an unconstrained search.
            return $this->searchRecordings(sprintf(
                'recording:"%s" AND artist:"%s"',
                $title,
                $artist,
            ));
        }

        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function lookupById(string $mbid): ?array
    {
        $response = $this->request("/recording/{$mbid}", [
            // release-groups carries primary/secondary type and the original
            // release date. Note: `labels` is not valid on the recording
            // resource — requesting it makes MusicBrainz reject the call.
            'inc' => 'artists+releases+release-groups+isrcs+genres+tags',
        ]);

        return $response?->json() ?: null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function searchRecordings(string $query): ?array
    {
        // Popular songs have dozens of bootleg recordings that tie with the
        // studio original at score 100, so cast a wide net and rank locally.
        $response = $this->request('/recording', [
            'query' => $query,
            'limit' => 25,
        ]);

        $candidates = $response?->json('recordings') ?? [];

        if (empty($candidates)) {
            return null;
        }

        $best = $this->preferredRecording($candidates);

        if (empty($best['id'])) {
            return null;
        }

        // Search rows are truncated — `first-release-date` there describes the
        // release that matched the query (often a recent reissue), and only one
        // release is listed. The full document carries every release and the
        // release-group's original date, which is what ranking actually needs.
        $full = $this->lookupById($best['id']);

        if ($full === null) {
            return $best;
        }

        // Re-rank the top candidates on full data, since the search-order pick
        // was made from that unreliable truncated view.
        return $this->bestOfFullLookups($candidates, $full);
    }

    /**
     * Re-ranks the leading candidates using their full documents.
     *
     * Fetches a bounded number of them (each is a separate rate-limited call)
     * and keeps whichever has the earliest original release date — the studio
     * original always predates the reissues and live versions derived from it.
     *
     * @param  array<int, array<string, mixed>>  $candidates
     * @param  array<string, mixed>  $alreadyFetched
     * @return array<string, mixed>
     */
    private function bestOfFullLookups(array $candidates, array $alreadyFetched): array
    {
        $best = $alreadyFetched;
        $bestDate = $this->earliestReleaseDate($alreadyFetched);

        $others = collect($candidates)
            ->reject(fn (array $r) => ($r['id'] ?? null) === ($alreadyFetched['id'] ?? null))
            ->take(7);

        foreach ($others as $candidate) {
            if (empty($candidate['id'])) {
                continue;
            }

            $full = $this->lookupById($candidate['id']);

            if ($full === null) {
                continue;
            }

            $date = $this->earliestReleaseDate($full);

            if ($date < $bestDate) {
                $best = $full;
                $bestDate = $date;
            }
        }

        return $best;
    }

    /**
     * The earliest original release date across everything a recording appears
     * on. Reissues report their own date, so the release-group date is used.
     *
     * @param  array<string, mixed>  $recording
     */
    private function earliestReleaseDate(array $recording): string
    {
        $dates = collect($recording['releases'] ?? [])
            // Bootlegs muddy the signal and are never the canonical origin.
            ->filter(fn (array $r) => ($r['status'] ?? '') === 'Official')
            ->map(fn (array $r) => $this->releaseDate($r))
            ->filter(fn (string $d) => $d !== '9999');

        return $dates->min() ?? $recording['first-release-date'] ?? '9999';
    }

    /**
     * Picks the most canonical recording from a search result set.
     *
     * MusicBrainz ranks purely by text relevance, so dozens of bootlegs tie at
     * score 100 with the studio original. Search results also truncate each
     * recording's release list to a single entry, so a recording's own releases
     * can't be trusted to reveal whether a studio version exists.
     *
     * What does discriminate reliably is `first-release-date`: the studio
     * original predates the live and compilation appearances derived from it.
     *
     * @param  array<int, array<string, mixed>>  $recordings
     * @return array<string, mixed>|null
     */
    private function preferredRecording(array $recordings): ?array
    {
        $top = $recordings[0]['score'] ?? 100;

        return collect($recordings)
            // Anything far below the best text match is a different song.
            ->filter(fn (array $r) => ($r['score'] ?? 0) >= min(90, $top))
            ->sortBy([
                // A recording with no official release at all ranks last.
                fn (array $r) => $this->hasOfficialRelease($r) ? 0 : 1,
                // Live and bootleg appearances are titled by venue and date.
                fn (array $r) => $this->looksLikeLiveRecording($r) ? 1 : 0,
                // Earliest release wins — the original precedes its live and
                // compilation descendants. Undated entries sort to the end.
                fn (array $r) => $r['first-release-date'] ?? '9999',
            ])
            ->first() ?? $recordings[0] ?? null;
    }

    /**
     * Live and bootleg releases are overwhelmingly titled by date and venue
     * ("1984-09-15: Sports Palace, Milan"), or carry a Live secondary type.
     * Both are strong signals this isn't the studio original.
     *
     * @param  array<string, mixed>  $recording
     */
    private function looksLikeLiveRecording(array $recording): bool
    {
        if (filled($recording['disambiguation'] ?? null)
            && str_contains(strtolower($recording['disambiguation']), 'live')) {
            return true;
        }

        foreach ($recording['releases'] ?? [] as $release) {
            $title = $release['title'] ?? '';

            // Leading date stamp, e.g. "1984-09-15: ..." or "1984‐09‐15: ..."
            if (preg_match('/^\d{4}[\-\x{2010}\x{2011}\x{2012}\x{2013}]\d{2}/u', $title)) {
                return true;
            }

            $group = $release['release-group'] ?? $release;
            $secondary = array_map('strtolower', $group['secondary-types'] ?? []);

            if (in_array('live', $secondary, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $recording
     */
    private function hasOfficialRelease(array $recording): bool
    {
        foreach ($recording['releases'] ?? [] as $release) {
            if (($release['status'] ?? '') === 'Official') {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $recording
     */
    private function writeRecordingFields(MediaItem $item, array $recording): void
    {
        $meta = $item->musicMetadata;

        if (! $meta) {
            return;
        }

        $values = array_filter([
            'musicbrainz_recording_id' => $recording['id'] ?? null,
            'isrc' => $recording['isrcs'][0] ?? null,
            'duration_ms' => $recording['length'] ?? null,
            'artist' => $recording['artist-credit'][0]['name'] ?? null,
        ], fn ($v) => $v !== null && $v !== '');

        $this->fillBlank($item, $values);
    }

    /**
     * Writes the artist credits from MusicBrainz's structured `artist-credit`,
     * which carries each artist's own name *and* stable MBID — the better source
     * the enrichment used to parse back out of a joined string (S-38). When this
     * runs, the credits carry ids; the string-parsing fallback in the enrich job
     * then leaves them alone rather than re-attaching the same names without ids.
     *
     * @param  array<string, mixed>  $recording
     */
    private function writeCredits(MediaItem $item, array $recording): void
    {
        $artistCredit = $recording['artist-credit'] ?? [];

        if (! is_array($artistCredit) || $artistCredit === []) {
            return;
        }

        app(MusicCredits::class)->fromMusicBrainz($item, $artistCredit);
    }

    /**
     * The first release a recording appears on supplies album, year, and label.
     *
     * @param  array<string, mixed>  $recording
     */
    private function writeReleaseFields(MediaItem $item, array $recording): void
    {
        $release = $this->preferredRelease(
            $recording['releases'] ?? [],
            $recording['first-release-date'] ?? null,
        );

        if (empty($release)) {
            return;
        }

        $values = array_filter([
            'musicbrainz_release_id' => $release['id'] ?? null,
            'album' => $release['title'] ?? null,
            // Original album date, not the date of whichever pressing matched.
            'release_year' => $this->extractYear($this->releaseDate($release)),
            'label' => $this->lookupLabel($release['id'] ?? null),
        ], fn ($v) => $v !== null && $v !== '');

        $this->fillBlank($item, $values);
    }

    /**
     * A recording often appears on dozens of releases — compilations, live
     * albums, and reissues alongside the original. Prefer an official studio
     * album, then fall back to the earliest release, so items land on the
     * canonical album rather than whichever release MusicBrainz returned first.
     *
     * @param  array<int, array<string, mixed>>  $releases
     * @return array<string, mixed>|null
     */
    private function preferredRelease(array $releases, ?string $recordingDate = null): ?array
    {
        if (empty($releases)) {
            return null;
        }

        $scored = collect($releases)
            ->sortBy([
                // An undated release tells us nothing and is usually an obscure
                // regional pressing; rank it below anything with a real date.
                fn (array $r) => $this->releaseDate($r) === '9999' ? 1 : 0,
                // Official status beats bootleg/promo.
                fn (array $r) => ($r['status'] ?? '') === 'Official' ? 0 : 1,
                // An album proper beats a compilation, live, or single.
                fn (array $r) => $this->isStudioAlbum($r) ? 0 : 1,
                // The album released the same year the recording first appeared
                // is the one it actually debuted on. Without this a later studio
                // album can outrank the original simply by sorting earlier on
                // some other field.
                fn (array $r) => $this->matchesRecordingYear($r, $recordingDate) ? 0 : 1,
                // Among equals, the earliest dated release is the original.
                fn (array $r) => $this->releaseDate($r),
            ]);

        return $scored->first();
    }

    /**
     * Whether a release came out the same year the recording first appeared.
     *
     * @param  array<string, mixed>  $release
     */
    private function matchesRecordingYear(array $release, ?string $recordingDate): bool
    {
        if (blank($recordingDate)) {
            return false;
        }

        $releaseYear = $this->extractYear($this->releaseDate($release));
        $recordingYear = $this->extractYear($recordingDate);

        return $releaseYear !== null && $releaseYear === $recordingYear;
    }

    /**
     * The issuing label, which lives on the release resource rather than the
     * recording — `labels` is not a valid inc parameter when fetching a
     * recording, so this needs its own call.
     */
    private function lookupLabel(?string $releaseId): ?string
    {
        if (blank($releaseId)) {
            return null;
        }

        $response = $this->request("/release/{$releaseId}", ['inc' => 'labels']);

        return $response?->json('label-info.0.label.name');
    }

    /**
     * The date to rank a release by, and to store as the release year.
     *
     * A release row carries the date of *that pressing* — a 2001 remaster of a
     * 1975 album reports 2001. The release-group's first-release-date is the
     * album's original date, which is what a catalog should show.
     *
     * @param  array<string, mixed>  $release
     */
    private function releaseDate(array $release): string
    {
        return $release['release-group']['first-release-date']
            ?? $release['date']
            ?? '9999';
    }

    /**
     * @param  array<string, mixed>  $release
     */
    private function isStudioAlbum(array $release): bool
    {
        // Lookups nest this under `release-group`; search results sometimes
        // flatten the type onto the release itself.
        $group = $release['release-group'] ?? $release;

        if (($group['primary-type'] ?? null) !== 'Album') {
            return false;
        }

        // Secondary types mark compilations, live albums, soundtracks, remixes.
        return empty($group['secondary-types']);
    }

    /**
     * @param  array<string, mixed>  $recording
     */
    private function writeGenreTags(MediaItem $item, array $recording): void
    {
        $aliases = config('metadata_rules.genre_aliases', []);

        // `genres` is curated; `tags` is community-submitted. Prefer genres.
        $names = collect($recording['genres'] ?? [])
            ->sortByDesc('count')
            ->pluck('name')
            ->take(3);

        foreach ($names as $name) {
            $canonical = $aliases[strtolower($name)] ?? str($name)->title()->toString();

            $exists = $item->tags()
                ->where('type', 'genre')
                ->where('value', $canonical)
                ->exists();

            if ($exists) {
                continue;
            }

            $item->tags()->create([
                'type' => 'genre',
                'value' => $canonical,
                'source' => MediaTagSource::Api,
            ]);
        }
    }

    /**
     * Writes only fields that are still empty, so file tags and manual edits
     * (both of which run earlier) always win.
     *
     * @param  array<string, mixed>  $values
     */
    private function fillBlank(MediaItem $item, array $values): void
    {
        $meta = $item->musicMetadata;

        if (! $meta) {
            return;
        }

        $dirty = false;

        foreach ($values as $field => $value) {
            if (blank($meta->{$field})) {
                $meta->{$field} = $value;
                $dirty = true;
            }
        }

        if ($dirty) {
            $meta->saveQuietly();
        }
    }

    /**
     * @param  array<string, mixed>  $query
     */
    private function request(string $path, array $query): ?Response
    {
        $response = Http::withHeaders([
            // MusicBrainz blocks clients without a descriptive User-Agent.
            'User-Agent' => sprintf(
                '%s/1.0 (%s)',
                config('app.name', 'SoundChex'),
                config('app.url', 'https://github.com/tripsittr/SoundChex'),
            ),
        ])
            ->acceptJson()
            ->timeout(10)
            ->retry(2, 1000, throw: false)
            ->get(self::BASE.$path, $query + ['fmt' => 'json']);

        return $response->successful() ? $response : null;
    }

    private function extractYear(?string $date): ?int
    {
        if (blank($date) || ! preg_match('/(\d{4})/', $date, $m)) {
            return null;
        }

        return (int) $m[1];
    }

    /** Lucene reserves these; an unescaped quote breaks the query. */
    private function escapeLucene(string $value): string
    {
        return preg_replace('/([+\-&|!(){}\[\]^"~*?:\\\\\/])/', '\\\\$1', $value);
    }
}
