<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Services\Import;

use App\Enums\MediaItemType;
use App\Models\MediaItem;
use App\Services\ContentGate;
use Illuminate\Database\Eloquent\Builder;

/**
 * Matches an imported playlist track to a library item (S-310).
 *
 * Mirrors the duplicate detector's identity cascade — the most trustworthy
 * evidence first — so a ported playlist points at the same recording the rest of
 * the app already considers a match:
 *
 *   1. ISRC — the recording's global id; decisive.
 *   2. MusicBrainz recording id.
 *   3. Spotify id (when the source is Spotify and the library carries it).
 *   4. Fuzzy — normalised artist + title, album when known, duration within
 *      tolerance. This is what carries a tags-not-ids library.
 *
 * Every candidate query is routed through the ContentGate, so a track a profile
 * may not see is never matched into its playlist.
 */
class TrackMatcher
{
    public function __construct(private ContentGate $gate) {}

    /**
     * The library item this track is, or null when nothing matches confidently.
     */
    public function match(ImportedTrack $track): ?MediaItem
    {
        if ($track->isEmpty()) {
            return null;
        }

        return $this->byIdentifier($track) ?? $this->byFuzzy($track);
    }

    /**
     * A match on a recording identifier, tried in descending confidence.
     */
    private function byIdentifier(ImportedTrack $track): ?MediaItem
    {
        $identifiers = array_filter([
            'isrc' => $track->isrc,
            'musicbrainz_recording_id' => $track->musicbrainzRecordingId,
            'spotify_id' => $track->spotifyId,
        ], fn ($v) => filled($v));

        foreach ($identifiers as $column => $value) {
            $match = $this->candidates()
                ->whereHas('musicMetadata', fn (Builder $q) => $q->where($column, $value))
                ->orderBy('id')
                ->first();

            if ($match !== null) {
                return $match;
            }
        }

        return null;
    }

    /**
     * A fuzzy match on normalised artist + title (+ album, + duration), the same
     * comparison the duplicate detector uses so both agree on what is "the same
     * recording".
     */
    private function byFuzzy(ImportedTrack $track): ?MediaItem
    {
        $title = $this->normalise((string) $track->title);
        $artist = $this->normalise((string) $track->artist);

        if ($title === '' || $artist === '') {
            return null;
        }

        return $this->candidates()
            ->whereRaw('LOWER(TRIM(title)) = ?', [$title])
            ->whereHas('musicMetadata', function (Builder $q) use ($track, $artist): void {
                // Primary artist, falling back to the raw credit — so "Artist" and
                // "Artist, Someone" (the same song, one tagged with a feature) pair
                // up, exactly as the duplicate detector pairs them.
                $q->whereRaw('LOWER(TRIM(COALESCE(NULLIF(primary_artist, ""), artist))) = ?', [$artist]);

                // Album must match when the imported track names one — a single and
                // the album cut of the same song are legitimately different files.
                if (filled($track->album)) {
                    $q->whereRaw('LOWER(TRIM(COALESCE(album, ""))) = ?', [$this->normalise($track->album)]);
                }

                // Length within tolerance, when both sides know it. A missing
                // library duration is allowed rather than treated as a mismatch.
                if ($track->durationMs !== null) {
                    $tolerance = 3000;
                    $q->where(function (Builder $inner) use ($track, $tolerance): void {
                        $inner->whereNull('duration_ms')
                            ->orWhereBetween('duration_ms', [
                                $track->durationMs - $tolerance,
                                $track->durationMs + $tolerance,
                            ]);
                    });
                }
            })
            ->orderBy('id')
            ->first();
    }

    /**
     * The base set of library items a track may match: music the current profile
     * is allowed to see, and not itself a flagged duplicate.
     */
    private function candidates(): Builder
    {
        return $this->gate->apply(MediaItem::query())
            ->where('type', MediaItemType::Music)
            ->whereNull('duplicate_of_id');
    }

    private function normalise(string $value): string
    {
        return mb_strtolower(trim($value));
    }
}
