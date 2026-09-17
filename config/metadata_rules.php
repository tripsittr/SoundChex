<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

/**
 * Filename parsing rules — used by the FileTagger source when a file carries
 * no usable embedded tags.
 *
 * Each pattern is tried in order against the filename (extension stripped).
 * The first match wins. Named capture groups map directly onto fields:
 *
 *   artist, album, title, track_number, release_year, bpm, key, scale
 *
 * Add or reorder patterns freely — no code changes needed. Put more specific
 * patterns first, since matching stops at the first hit.
 */

return [
    'filename_patterns' => [
        // "120_Cmaj_Kick_Hard" → bpm + key + scale + title
        '/^(?<bpm>\d{2,3})[_\-\s]+(?<key>[A-G][#b]?)(?<scale>maj|min)[_\-\s]+(?<title>.+)$/i',

        // "[2024] Artist - Title"
        '/^\[(?<release_year>\d{4})\]\s*(?<artist>.+?)\s*-\s*(?<title>.+)$/',

        // "Artist - Album - 01 Title"
        '/^(?<artist>.+?)\s*-\s*(?<album>.+?)\s*-\s*(?<track_number>\d{1,3})[\.\s\-]+(?<title>.+)$/',

        // "01 - Artist - Title"
        '/^(?<track_number>\d{1,3})\s*-\s*(?<artist>.+?)\s*-\s*(?<title>.+)$/',

        // "01. Title" or "01 - Title"
        '/^(?<track_number>\d{1,3})[\.\s\-]+(?<title>.+)$/',

        // "Artist - Title" (most common; keep last of the dash forms)
        '/^(?<artist>.+?)\s*-\s*(?<title>.+)$/',
    ],

    /**
     * Normalizes the scale token captured above into the canonical values
     * stored in music_metadata.scale.
     */
    'scale_map' => [
        'maj'   => 'major',
        'major' => 'major',
        'min'   => 'minor',
        'minor' => 'minor',
        'm'     => 'minor',
    ],

    /**
     * Genre strings that different sources spell differently, normalized to a
     * single canonical form before being stored as a tag.
     */
    'genre_aliases' => [
        'hip hop'      => 'Hip-Hop',
        'hiphop'       => 'Hip-Hop',
        'hip-hop/rap'  => 'Hip-Hop',
        'rnb'          => 'R&B',
        'r&b/soul'     => 'R&B',
        'drum and bass' => 'Drum & Bass',
        'dnb'          => 'Drum & Bass',
        'electronica'  => 'Electronic',
        'alt rock'     => 'Alternative Rock',
    ],

    /**
     * Separators used to split multi-value tag fields (genre, artist) that
     * arrive as a single delimited string.
     */
    'multi_value_separators' => ['/', ';', ',', ' feat. ', ' ft. ', ' & '],
];
