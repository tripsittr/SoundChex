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
     * Community tags that are not genres, as patterns matched case-insensitively
     * against the whole lower-cased tag.
     *
     * Last.fm's tags are a folksonomy, so alongside "shoegaze" and "post-punk"
     * come "seen live", "albums i own", "favourites" and "awesome". Written into
     * `media_tags` as genres they reach the genre filter and the genre chart,
     * which are the library's one curated facet — and a genre list containing
     * "seen live" is a genre list nobody trusts again.
     *
     * These are rejected rather than canonicalised. "seen live" is not a
     * misspelled genre, and mapping it to one would invent a fact.
     *
     * Decades and years are handled in code, not here: they are a shape
     * ("80s", "1990s", "2010") rather than a list.
     */
    'tag_blocklist' => [
        // Possession and personal lists.
        '\b(my|i|mine)\b',
        'own(ed)?$',
        'albums? i ',
        'collection',
        'library',
        'playlist',
        'wishlist',

        // Judgements and reactions.
        'favou?rite',
        'best',
        'worst',
        'awesome',
        'amazing',
        'perfect',
        'beautiful',
        'love',
        'brilliant',
        'masterpiece',
        'overrated',
        'underrated',
        'guilty pleasure',

        // Listening circumstances, not the music.
        'seen live',
        'want to see live',
        'to listen',
        'to check out',
        'heard on',
        'radio',
        'spotify',
        'vinyl',
        'cd$',
        'mp3',
        'itunes',

        // Nationality and language: real facts, and not genres. A genre filter
        // offering "british" next to "shoegaze" is answering a different
        // question than the one it was asked.
        '^(british|american|english|scottish|irish|welsh|canadian|australian|german|french|swedish|norwegian|japanese|korean|spanish|italian|dutch|danish|finnish|polish|russian|brazilian|mexican)$',

        // Gender and line-up descriptions.
        'vocalist',
        'female',
        'male',
        'singer.songwriter.*female',
        'band$',
        'duo$',
        'solo',

        // Catch-alls that say nothing.
        '^music$',
        '^songs?$',
        '^album$',
        '^artist$',
        '^track$',
        '^good',
        '^cool',
        '^nice',
        '^other$',
        '^misc',
        '^various',
        '^unknown$',
        '^untagged$',
        '^\W+$',
    ],

    /**
     * Separators used to split multi-value tag fields (genre, artist) that
     * arrive as a single delimited string.
     */
    'multi_value_separators' => ['/', ';', ',', ' feat. ', ' ft. ', ' & '],
];
