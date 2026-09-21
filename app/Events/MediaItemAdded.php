<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Events;

use App\Events\Concerns\PluginEvent;
use App\Models\MediaItem;

/**
 * A new item finished enriching and settled into the library — distinct from being catalogued, this fires once it is a real, kept item.
 *
 * Subscribe via `Registry::on('media.added', …)`.
 */
class MediaItemAdded
{
    use PluginEvent;

    public const NAME = 'media.added';

    public function __construct(public readonly MediaItem $item) {}
}
