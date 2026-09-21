<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Events;

use App\Events\Concerns\PluginEvent;
use App\Models\MediaItem;

/**
 * A cover image was baked into an item's audio file.
 *
 * Subscribe via `Registry::on('cover.embedded', …)`.
 */
class CoverEmbedded
{
    use PluginEvent;

    public const NAME = 'cover.embedded';

    public function __construct(public readonly MediaItem $item) {}
}
