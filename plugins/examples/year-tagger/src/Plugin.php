<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace SoundChex\YearTagger;

use App\Plugins\Contracts\SoundChexPlugin;
use App\Plugins\Registry;

/**
 * A worked-example plugin (S-264).
 *
 * The smallest thing that is still a real plugin: one metadata source, no API
 * key, no configuration. It demonstrates the whole anatomy an author copies —
 * a manifest, an entry class implementing the contract, and a source registered
 * on the registry — so a richer source (a Last.fm or Discogs integration, the
 * kind S-39 defers here) is the same shape with an HTTP client bolted on.
 */
class Plugin implements SoundChexPlugin
{
    public function getId(): string
    {
        return 'soundchex.year-tagger';
    }

    public function register(Registry $registry): void
    {
        // Runs late (high priority number) so it only fills a year the real
        // providers left blank, for both films and shows.
        $registry->metadataSource('movie', YearFromFilename::class, 900);
        $registry->metadataSource('show', YearFromFilename::class, 900);
    }

    public function boot(Registry $registry): void
    {
        // Nothing to do at serving time.
    }
}
