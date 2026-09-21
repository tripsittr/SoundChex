<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Events;

use App\Events\Concerns\PluginEvent;
use App\Models\MediaItem;

/**
 * A new episode of a series already in the library was catalogued.
 *
 * Subscribe via `Registry::on('episode.added', …)`.
 */
class EpisodeAdded
{
    use PluginEvent;

    public const NAME = 'episode.added';

    public function __construct(public readonly MediaItem $item) {}
}
