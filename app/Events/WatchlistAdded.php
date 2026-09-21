<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Events;

use App\Events\Concerns\PluginEvent;
use App\Models\MediaItem;

/**
 * An item was added to a profile's watchlist — the hook an 'add to Radarr/Sonarr' automation wants.
 *
 * Subscribe via `Registry::on('watchlist.added', …)`.
 */
class WatchlistAdded
{
    use PluginEvent;

    public const NAME = 'watchlist.added';

    public function __construct(public readonly MediaItem $item, public readonly ?int $profileId = null) {}
}
