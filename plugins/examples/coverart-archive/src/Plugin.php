<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace SoundChex\CoverArtArchive;

use App\Plugins\Contracts\SoundChexPlugin;
use App\Plugins\Registry;

/**
 * Registers a fallback cover source backed by the Cover Art Archive (S-264,
 * #280). The worked example of a `cover-source` plugin — one contribution, the
 * lookup lives in its own class.
 */
class Plugin implements SoundChexPlugin
{
    public function getId(): string
    {
        return 'soundchex.coverart-archive';
    }

    public function register(Registry $registry): void
    {
        $registry->coverSource(CoverArtArchiveSource::class, priority: 50);
    }

    public function boot(Registry $registry): void
    {
        //
    }
}
