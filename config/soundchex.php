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
