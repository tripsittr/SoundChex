<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Events;

use App\Events\Concerns\PluginEvent;
use App\Models\MediaItem;

/**
 * A resume position was saved for an item.
 *
 * Subscribe via `Registry::on('playback.progress', …)`.
 */
class PlaybackProgress
{
    use PluginEvent;

    public const NAME = 'playback.progress';

    public function __construct(public readonly MediaItem $item, public readonly ?int $profileId, public readonly int $position) {}
}
