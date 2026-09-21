<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Events;

use App\Events\Concerns\PluginEvent;
use App\Models\MediaItem;

/**
 * An item was removed from a profile's watchlist.
 *
 * Subscribe via `Registry::on('watchlist.removed', …)`.
 */
class WatchlistRemoved
{
    use PluginEvent;

    public const NAME = 'watchlist.removed';

    public function __construct(public readonly MediaItem $item, public readonly ?int $profileId = null) {}
}
