<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Services\Versions;

use App\Enums\MediaItemType;
use App\Models\MediaItem;

/**
 * The identity of the *work* a file holds (#457).
 *
 * Not the identity of the file — that is `content_hash` — and not the identity
 * of the release. Two copies of one recording on different albums share a work
 * key; a remaster and the original share it too. That is the point: it is what
 * lets them be shown as versions of one thing instead of offered as duplicates
 * to resolve.
 *
 * Derived only from identifiers a provider issued. A key built from a title
 * would group two different songs that happen to share a name, and the whole
 * value of this is that a shared key is a fact rather than a guess — so a file
 * nothing has identified simply has no key, and takes no part in grouping.
 */
class WorkKey
{
    /**
     * The work key for an item, or null when nothing identifies it.
     *
     * Null is a real answer and the common one mid-import. It must never be
     * replaced with something derived from the filename.
     */
    public function for(MediaItem $item): ?string
    {
        return match ($item->type) {
            MediaItemType::Music => $this->forMusic($item),
            MediaItemType::Movie => $this->forMovie($item),
            MediaItemType::Show => $this->forEpisode($item),
            MediaItemType::Book => $this->forBook($item),
        };
    }

    /**
     * A recording, by MusicBrainz id or ISRC.
     *
     * The *recording*, deliberately, not the release: a recording is the
     * performance, and the same performance appearing on an album, a single
     * and a compilation is exactly the case this exists to keep together.
     *
     * The release id is never used as a work key — it would group every track
     * on an album into one "work", which is nonsense.
     */
    private function forMusic(MediaItem $item): ?string
    {
        $meta = $item->musicMetadata;

        if (filled($meta?->musicbrainz_recording_id)) {
            return 'mb:recording:'.strtolower(trim($meta->musicbrainz_recording_id));
        }

        if (filled($meta?->isrc)) {
            // An ISRC identifies a recording too, and is the only identifier
            // many files carry. Normalised, because taggers write it with and
            // without hyphens.
            return 'isrc:'.strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $meta->isrc) ?: '');
        }

        return null;
    }

    private function forMovie(MediaItem $item): ?string
    {
        $meta = $item->movieMetadata;

        if (filled($meta?->tmdb_id)) {
            return 'tmdb:movie:'.$meta->tmdb_id;
        }

        if (filled($meta?->imdb_id)) {
            return 'imdb:'.strtolower(trim($meta->imdb_id));
        }

        return null;
    }

    /**
     * An episode, by series and numbering.
     *
     * Numbering rather than the episode's own id, because season and episode
     * are the strongest identity an episode has — series reuse titles across
     * seasons, and numbering does not. A series row itself is not a work: it
     * has no file.
     */
    private function forEpisode(MediaItem $item): ?string
    {
        $meta = $item->showMetadata;

        if ($meta === null || ! $item->isEpisode()) {
            return null;
        }

        $seriesId = $meta->tmdb_id
            ?? $item->parent?->showMetadata?->tmdb_id;

        if (blank($seriesId) || blank($meta->season_number) || blank($meta->episode_number)) {
            return null;
        }

        return sprintf(
            'tmdb:tv:%s:s%02de%02d',
            $seriesId,
            (int) $meta->season_number,
            (int) $meta->episode_number,
        );
    }

    private function forBook(MediaItem $item): ?string
    {
        $meta = $item->bookMetadata;

        // ISBN-13 first: it is the current standard, and two printings of one
        // book can share a 13 while differing on legacy 10s.
        $isbn = $meta?->isbn_13 ?: $meta?->isbn_10;

        if (blank($isbn)) {
            return null;
        }

        // Digits and a possible trailing X, which is a valid ISBN-10 check
        // character. Hyphens vary by printing and mean nothing.
        return 'isbn:'.strtoupper(preg_replace('/[^0-9Xx]/', '', $isbn) ?: '');
    }
}
