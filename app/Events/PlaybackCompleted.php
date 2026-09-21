<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Events;

use App\Events\Concerns\PluginEvent;
use App\Models\MediaItem;

/**
 * An item was watched or listened to the end (past the completion threshold) — the 'watched'/'scrobble' signal a tracker plugin reports.
 *
 * Subscribe via `Registry::on('playback.completed', …)`.
 */
class PlaybackCompleted
{
    use PluginEvent;

    public const NAME = 'playback.completed';

    public function __construct(public readonly MediaItem $item, public readonly ?int $profileId = null) {}
}
