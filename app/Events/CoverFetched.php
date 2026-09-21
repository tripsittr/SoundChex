<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Events;

use App\Events\Concerns\PluginEvent;
use App\Models\MediaItem;

/**
 * A verified album cover was fetched for an item.
 *
 * Subscribe via `Registry::on('cover.fetched', …)`.
 */
class CoverFetched
{
    use PluginEvent;

    public const NAME = 'cover.fetched';

    public function __construct(public readonly MediaItem $item, public readonly ?string $coverUrl = null) {}
}
