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
 * porting engine — parsing, matching, playlist creation — is core code
 * (S-310); this plugin is the admin screen that drives it, contributed through
 * the plugin admin-page seam rather than added as a core page. Bundled and
 * always on, so a fresh server can import a playlist from day one.
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
    }

    public function boot(Registry $registry): void
    {
        // Nothing at serving time — the page does the work on submit.
    }
}
