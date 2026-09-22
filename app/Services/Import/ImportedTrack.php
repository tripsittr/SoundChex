<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Services\Import;

/**
 * One track from an imported playlist, normalised across sources (S-310).
 *
 * A Spotify export, an Apple Music file, an M3U and a CSV all describe a track
 * differently; each source's parser reduces its entries to this common shape so
 * the matcher has one thing to reason about. Every field is optional but the
 * more that are present, the more confidently the track matches a library item —
 * an ISRC is decisive, artist+title is a fuzzy guess.
 */
final class ImportedTrack
{
    public function __construct(
        public readonly ?string $title = null,
        public readonly ?string $artist = null,
        public readonly ?string $album = null,
        public readonly ?string $isrc = null,
        public readonly ?string $musicbrainzRecordingId = null,
        public readonly ?string $spotifyId = null,
        public readonly ?int $durationMs = null,
        /** The source's own line/label for this track, shown when it can't be matched. */
        public readonly ?string $sourceLabel = null,
    ) {}

    /** A human label for an unmatched track — "Artist – Title", or whatever we have. */
    public function label(): string
    {
        $parts = array_filter([$this->artist, $this->title]);

        if ($parts !== []) {
            return implode(' – ', $parts);
        }

        return $this->sourceLabel ?? 'Unknown track';
    }

    /** Whether there is anything to match on at all. */
    public function isEmpty(): bool
    {
        return blank($this->isrc)
            && blank($this->musicbrainzRecordingId)
            && blank($this->spotifyId)
            && (blank($this->title) || blank($this->artist));
    }

    /** For storing an unmatched track back to the caller as plain data. */
    public function toArray(): array
    {
        return array_filter([
            'title' => $this->title,
            'artist' => $this->artist,
            'album' => $this->album,
            'isrc' => $this->isrc,
            'musicbrainz_recording_id' => $this->musicbrainzRecordingId,
            'spotify_id' => $this->spotifyId,
            'duration_ms' => $this->durationMs,
            'source_label' => $this->sourceLabel,
        ], fn ($v) => $v !== null && $v !== '');
    }
}
