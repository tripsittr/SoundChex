<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

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

        // The directory scanned for installed plugins. Outside the app's own
        // source tree so a plugin is data on disk, added and removed without a
        // deploy — the Emby-style install the platform is built around.
        'path' => storage_path('app/plugins'),

        // First-party plugins that ship with the app and are always on. These
        // are the platform dogfooding itself: behaviours that could be core but
        // are written as plugins (the title tidier, cover sources, notification
        // targets). They live in the repo, load enabled, and never appear in the
        // install/enable flow — disabling one is `SOUNDCHEX_PLUGINS_ENABLED` or
        // editing the app, not a toggle, because they are the app.
        'bundled_path' => base_path('plugins/bundled'),

        // A master switch. Off, the loader does nothing and no third-party code
        // runs — the kill switch that makes "disable everything" a config flip.
        'enabled' => env('SOUNDCHEX_PLUGINS_ENABLED', true),

    ],

];
