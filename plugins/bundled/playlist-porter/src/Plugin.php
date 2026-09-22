<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace SoundChex\PlaylistPorter;

use App\Plugins\Contracts\SoundChexPlugin;
use App\Plugins\Registry;
use Illuminate\Support\Facades\View;
use SoundChex\PlaylistPorter\Filament\ImportPlaylist;

/**
 * Playlist porting, as a first-party bundled plugin (S-311).
 *
 * Phase 2 of the porting feature (S-309): the desktop/server face of it. The
 * porting engine — parsing, matching, playlist creation — lives entirely in this
 * plugin (S-315): its services, job, model, migration, API routes and the admin
 * screen that drives them, contributed through the plugin seams rather than added
 * to core. Bundled and always on, so a fresh server can import a playlist from
 * day one; disabling it removes the table, the endpoints and the page together.
 */
class Plugin implements SoundChexPlugin
{
    public function getId(): string
    {
        return 'soundchex.playlist-porter';
    }

    public function register(Registry $registry): void
    {
        // The page's blade lives in this plugin, under a `playlist-porter` view
        // namespace so `playlist-porter::import-playlist` resolves.
        View::addNamespace('playlist-porter', __DIR__.'/../resources/views');

        // Add the Import Playlist page to the admin. The page gates itself to
        // server admins via its own concern; this only makes Filament aware of it.
        $registry->adminPage(ImportPlaylist::class);

        // The plugin owns its own schema — the playlist_imports table — so it runs
        // with the core migrations and disabling the plugin takes its table with it.
        $registry->migrations(__DIR__.'/../database/migrations');

        // The plugin owns its own API too: the /playlists/imports and
        // /playlists/sources endpoints. Under the same api/v1 prefix and
        // auth:sanctum guard the core API uses, so the URLs are unchanged.
        $registry->routes(__DIR__.'/../routes/api.php', prefix: 'api/v1', middleware: ['auth:sanctum']);
    }

    public function boot(Registry $registry): void
    {
        // Nothing at serving time — the page does the work on submit.
    }
}
