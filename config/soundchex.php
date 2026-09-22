<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

use App\Support\DataPaths;

return [

    /*
    |--------------------------------------------------------------------------
    | Server version
    |--------------------------------------------------------------------------
    |
    | The version a plugin's `minSoundChexVersion` is gated against (S-264).
    | SemVer, bumped per release with a music-themed minor name. This is the
    | one authoritative server version — clients report their own separately.
    |
    */
    'version' => '0.1.0',

    /*
    |--------------------------------------------------------------------------
    | Plugin API version
    |--------------------------------------------------------------------------
    |
    | The version of the *plugin contract* — separate from the app version so a
    | plugin survives app releases (S-264). A plugin declares the API it was
    | built for (`targetApi` in its manifest); it keeps loading as long as this
    | server's plugin API has the **same major** version. The rule:
    |
    |   - PATCH bump (1.2.0 -> 1.2.1): a fix, no contract change. Plugins survive.
    |   - MINOR bump (1.2.0 -> 1.3.0): new seams/events added, nothing removed.
    |     Plugins survive — a plugin built for 1.2 runs on 1.9.
    |   - MAJOR bump (1.x -> 2.0.0): the overhaul that removes or changes the
    |     contract. Plugins built for the old major are refused until updated.
    |
    | So bump MAJOR only for a deliberate breaking overhaul; add events and
    | registry seams under a MINOR. A plugin with no `targetApi` is assumed to
    | target the current major (it takes its chances on a future overhaul).
    |
    */
    'plugin_api_version' => '1.0.0',

    /*
    |--------------------------------------------------------------------------
    | Plugins
    |--------------------------------------------------------------------------
    |
    | Where installed plugins live and whether the loader runs at all. Each
    | plugin is a directory under `path` holding a `plugin.json` manifest and
    | its PHP under the namespace the manifest declares.
    |
    */
    'plugins' => [

        // The directory scanned for installed plugins. Resolved to an
        // OS-conventional per-user data directory *outside the install* (see
        // App\Support\DataPaths) so a downloaded server release keeps an
        // operator's plugins across upgrades and never shows a build machine's
        // path. `SOUNDCHEX_PLUGINS_PATH` (or `SOUNDCHEX_DATA_DIR`) overrides it.
        'path' => DataPaths::plugins(),

        // A master switch. Off, the loader does nothing and no third-party code
        // runs — the kill switch that makes "disable everything" a config flip.
        // There are no bundled/always-on plugins (S-321): a fresh install has no
        // plugins until the operator installs one from a repository.
        'enabled' => env('SOUNDCHEX_PLUGINS_ENABLED', true),

        // The official plugin repository — a catalogue URL the app trusts by
        // default, so a fresh install can discover and install the first-party
        // plugins (activity log, title tidier, playlist porter) and any others
        // the project curates. Seeded as an `official` repository; users can add
        // their own repository URLs alongside it. Empty until published.
        'official_repository' => env('SOUNDCHEX_PLUGIN_REPOSITORY', ''),

        // Whether the local-only plugin-folder controls (the path and the
        // "Open Plugins Folder" button) are offered. Null — the default — lets
        // the request's own address decide (loopback = the home server). Set it
        // explicitly (SOUNDCHEX_PLUGINS_LOCAL_MANAGEMENT=false) when the server
        // sits behind a loopback reverse proxy, so every request looks local and
        // the address test would wrongly show those controls to remote admins.
        'local_management' => env('SOUNDCHEX_PLUGINS_LOCAL_MANAGEMENT'),

    ],

];
