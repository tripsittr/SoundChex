<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

/**
 * Streaming services and physical formats.
 *
 * Used two ways:
 *
 *   - `source_service` on an item: where the user's own copy came from. Set by
 *     hand; the physical entries below only ever appear here.
 *   - `media_availability`: where a title streams right now, fetched from TMDB.
 *     `tmdb_id` maps TMDB's provider ids onto these slugs.
 *
 * Colours are the service's own brand tone, used for the tile in "Browse by
 * Service" so the row is scannable without loading a dozen logo files.
 */

return [
    /*
    |--------------------------------------------------------------------------
    | Region
    |--------------------------------------------------------------------------
    |
    | Streaming rights are per-country, so availability is meaningless without
    | one. Two-letter ISO code.
    |
    */

    'region' => env('WATCH_PROVIDER_REGION', 'US'),

    /*
    |--------------------------------------------------------------------------
    | Services
    |--------------------------------------------------------------------------
    |
    | `tmdb_id` is TMDB's provider id, used to translate their watch-provider
    | response into these slugs. Entries without one are physical formats or
    | services TMDB doesn't track — they only ever appear as an owned source.
    |
    */

    'services' => [
        'netflix'        => ['name' => 'Netflix',          'tmdb_id' => 8,   'color' => '#e50914'],
        'amazon-prime'   => ['name' => 'Amazon Prime',     'tmdb_id' => 9,   'color' => '#00a8e1'],
        'disney-plus'    => ['name' => 'Disney+',          'tmdb_id' => 337, 'color' => '#113ccf'],
        'apple-tv-plus'  => ['name' => 'Apple TV+',        'tmdb_id' => 350, 'color' => '#000000'],
        'apple-tv'       => ['name' => 'Apple TV',         'tmdb_id' => 2,   'color' => '#333333'],
        'hulu'           => ['name' => 'Hulu',             'tmdb_id' => 15,  'color' => '#1ce783'],
        'hbo-max'        => ['name' => 'HBO Max',          'tmdb_id' => 1899,'color' => '#5822b4'],
        'paramount-plus' => ['name' => 'Paramount+',       'tmdb_id' => 531, 'color' => '#0064ff'],
        'peacock'        => ['name' => 'Peacock',          'tmdb_id' => 386, 'color' => '#f6ae2d'],
        'crunchyroll'    => ['name' => 'Crunchyroll',      'tmdb_id' => 283, 'color' => '#f47521'],
        'starz'          => ['name' => 'Starz',            'tmdb_id' => 43,  'color' => '#000000'],
        'amc-plus'       => ['name' => 'AMC+',             'tmdb_id' => 526, 'color' => '#c8102e'],
        'showtime'       => ['name' => 'Showtime',         'tmdb_id' => 37,  'color' => '#ff0000'],
        'mubi'           => ['name' => 'MUBI',             'tmdb_id' => 11,  'color' => '#001489'],
        'criterion'      => ['name' => 'Criterion Channel','tmdb_id' => 258, 'color' => '#00447c'],

        // Physical and personal sources — never returned by TMDB.
        'bluray'         => ['name' => 'Blu-ray',          'tmdb_id' => null, 'color' => '#0b3d91'],
        'dvd'            => ['name' => 'DVD',              'tmdb_id' => null, 'color' => '#4a4a4a'],
        'uhd'            => ['name' => '4K UHD',           'tmdb_id' => null, 'color' => '#1a1a1a'],
        'digital'        => ['name' => 'Digital Purchase', 'tmdb_id' => null, 'color' => '#2b7a78'],
        'other'          => ['name' => 'Other',            'tmdb_id' => null, 'color' => '#5a5a5a'],
    ],
];
