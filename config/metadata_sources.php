<?php

/**
 * Metadata source pipeline configuration.
 *
 * Each key is a MediaItemType value. Each value is an ordered list of
 * MetadataSource class names, lowest priority number first.
 *
 * Sources are filtered twice before running:
 *   1. MetadataPipeline skips any class that doesn't exist yet, so a source
 *      can be listed here before it's written.
 *   2. supports() skips sources whose API key isn't configured, so a keyless
 *      install still runs everything that doesn't need one.
 *
 * Entries below are written as plain strings rather than `::class` on purpose:
 * several are placeholders for sources that don't exist yet, and `::class`
 * would make static analysis and IDEs report them as undefined types.
 *
 * API keys live encrypted in the `settings` table and are entered under
 * Settings → Metadata Sources — never hardcoded here or in .env.
 *
 * ── Not yet implemented ──────────────────────────────────────────────────
 * The commented lines are planned sources. Uncomment one after writing the
 * class; nothing else needs to change.
 */

return [
    'music' => [
        'App\Services\Metadata\Sources\Music\FileTagger',      // 1 — ID3/Vorbis tags from the file itself (no key)
        'App\Services\Metadata\Sources\Music\AcoustId',        // 2 — audio fingerprint → identifies untagged files
        'App\Services\Metadata\Sources\Music\MusicBrainz',     // 3 — canonical IDs, releases, labels, ISRC (no key)
        'App\Services\Metadata\Sources\Music\ItunesSearch',    // 5 — artwork, genre, track preview (no key)
        'App\Services\Metadata\Sources\Music\Spotify',         // 6 — audio features: energy, danceability, valence

        // 'App\Services\Metadata\Sources\Music\Discogs',      // 4 — pressing details, catalog number
        // 'App\Services\Metadata\Sources\Music\Lastfm',       // 7 — community tags, similar artists, biography
        // 'App\Services\Metadata\Sources\Music\FanartTv',     // 8 — HD artist/album artwork
        // 'App\Services\Metadata\Sources\Music\Genius',       // 9 — lyrics, annotations
        // 'App\Services\Metadata\Sources\Music\Deezer',       // 10 — alternate catalog ID, BPM, contributors
    ],

    'movie' => [
        'App\Services\Metadata\Sources\Movie\Tmdb',            // 1 — title, overview, genres, cast, crew, poster

        // 'App\Services\Metadata\Sources\Movie\Omdb',         // 2 — IMDb rating, RT score, awards
        // 'App\Services\Metadata\Sources\Movie\Trakt',        // 3 — community ratings, watch history
        // 'App\Services\Metadata\Sources\Movie\FanartTv',     // 4 — HD posters, clearart, logos
    ],

    'show' => [
        'App\Services\Metadata\Sources\Show\Tmdb',             // 1 — series, seasons, episodes, cast, network

        // 'App\Services\Metadata\Sources\Show\TvMaze',        // 2 — episode-level detail, guest cast (no key)
        // 'App\Services\Metadata\Sources\Show\Tvdb',          // 3 — alternate episode ordering, episode art
        // 'App\Services\Metadata\Sources\Show\Trakt',         // 4 — ratings, progress, similar shows
        // 'App\Services\Metadata\Sources\Show\FanartTv',      // 5 — series banners, HD posters, season art
    ],

    'book' => [
        'App\Services\Metadata\Sources\Book\OpenLibrary',      // 1 — author, publisher, ISBN, subjects, cover (no key)

        // 'App\Services\Metadata\Sources\Book\GoogleBooks',   // 2 — description, page count, categories
        // 'App\Services\Metadata\Sources\Book\LibraryThing',  // 3 — community tags, series info
    ],

    /*
    | Subtitles. Not part of the enrichment pipeline — captions are fetched on
    | request rather than swept for — but listed here so the settings page
    | discovers the API key alongside every other source.
    */

    'subtitle' => [
        'App\Services\Subtitles\OpenSubtitles',               // free key, rate-limited downloads
    ],
];
