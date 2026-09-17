<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

/*
|---------------------------------------------------------------------------
| Acquisition apps
|---------------------------------------------------------------------------
|
| Radarr, Sonarr and Lidarr, if they are running. They do a different job from
| SoundChex — they find new media, SoundChex catalogs what exists — and they
| meet at the folder the scanner watches.
|
| Entirely optional. Nothing here is required for the library to work, and the
| admin page reports a stack that is not running as "not running" rather than
| failing. That is the normal state on a machine where Docker has not started.
|
| Readarr is absent on purpose: retired upstream in 2025, with a metadata
| backend that has been unreliable since. Books are enriched from Open Library.
|
| API keys are read from `SettingsService` first and only fall back to these,
| so a headless install can still be configured without the admin panel. The
| same rule as every other credential: never `env()` at the point of use.
|
*/

return [

    /*
    | Where the containers read and write, derived rather than demanded.
    |
    | Every one of these has a correct answer this app already knows, so asking
    | the user to work it out and paste it into `.env` is three chances to get
    | it wrong for no benefit. They stay overridable because a second disk for
    | downloads is a reasonable thing to want.
    |
    | The media root is the one that matters: it must be the folder the scanner
    | watches, or completed downloads land somewhere nothing looks at.
    */
    'paths' => [
        'media' => env('SOUNDCHEX_MEDIA_ROOT', storage_path('app/private/media')),
        'config' => env('ARR_CONFIG_ROOT', storage_path('app/arr')),
        'downloads' => env('ARR_DOWNLOADS_ROOT', storage_path('app/arr-downloads')),
    ],

    /*
    | The user files should be owned by. Defaults to whoever is running PHP,
    | which is the right answer on a self-hosted single-user install and stops
    | the containers writing root-owned files the scanner cannot read.
    */
    'puid' => env('ARR_PUID', function_exists('posix_getuid') ? posix_getuid() : 1000),
    'pgid' => env('ARR_PGID', function_exists('posix_getgid') ? posix_getgid() : 1000),

    'apps' => [

        'radarr' => [
            'label' => 'Radarr',
            'kind' => 'Films',
            // Loopback only. These have no authentication until it is set up
            // inside each app, and this machine is on a tailnet.
            'url' => env('RADARR_URL', 'http://127.0.0.1:7878'),
            'key' => env('RADARR_API_KEY'),
            'api' => 'v3',
            'media_type' => \App\Enums\MediaItemType::Movie,
        ],

        'sonarr' => [
            'label' => 'Sonarr',
            'kind' => 'TV',
            'url' => env('SONARR_URL', 'http://127.0.0.1:8989'),
            'key' => env('SONARR_API_KEY'),
            'api' => 'v3',
            'media_type' => \App\Enums\MediaItemType::Show,
        ],

        'lidarr' => [
            'label' => 'Lidarr',
            'kind' => 'Music',
            'url' => env('LIDARR_URL', 'http://127.0.0.1:8686'),
            'key' => env('LIDARR_API_KEY'),
            // v1, not v3. Radarr and Sonarr moved to v3; Lidarr never did, and
            // assuming they matched made a running Lidarr report itself as
            // stopped — every call 404'd and a 404 is indistinguishable from
            // nothing listening. Verified against 2.5.3: v1 answers, v3 does not.
            'api' => 'v1',
            'media_type' => \App\Enums\MediaItemType::Music,
        ],

    ],

    /*
    | Short, because this runs inside a page render and the usual answer to
    | "is it running?" on a machine without Docker is an immediate refused
    | connection. A long timeout here means the admin page hangs for anyone
    | who does not run the stack, which is most people.
    */
    'timeout' => env('ARR_TIMEOUT', 3),

    /*
    | How long a health answer is trusted. The page is refreshed often and
    | these apps are not interesting second to second; without this, four HTTP
    | calls run on every poll.
    */
    'cache_seconds' => env('ARR_CACHE_SECONDS', 15),

];
