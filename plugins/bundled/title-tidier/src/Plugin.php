<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace SoundChex\TitleTidier;

use App\Enums\MediaItemType;
use App\Models\MediaItem;
use App\Plugins\Contracts\SoundChexPlugin;
use App\Plugins\Registry;
use App\Services\TitleTidier;

/**
 * The app's own title cleanup, written as a first-party bundled plugin (S-264,
 * #279).
 *
 * "Artist - Song" arriving as a title should become "Song" — an opinion about
 * how a title should read that used to live hardcoded in the enrichment job.
 * Moving it here proves a core behaviour can be a plugin, and lets the reused
 * `TitleTidier` service stay the single implementation. It is bundled and always
 * on, so existing libraries see no change.
 *
 * Music only: only a track carries its own artist in the title this way.
 */
class Plugin implements SoundChexPlugin
{
    public function getId(): string
    {
        return 'soundchex.title-tidier';
    }

    public function register(Registry $registry): void
    {
        $registry->filter('metadata.title', function (string $title, MediaItem $item): string {
            if ($item->type !== MediaItemType::Music) {
                return $title;
            }

            return app(TitleTidier::class)->strip($title, [
                $item->musicMetadata?->artist,
                $item->musicMetadata?->primary_artist,
            ]) ?? $title;
        });
    }

    public function boot(Registry $registry): void
    {
        //
    }
}
