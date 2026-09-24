<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Events;

use App\Events\Concerns\PluginEvent;
use App\Models\MediaItem;

/**
 * A play was recorded for an item (S-264 Phase 3).
 *
 * Fired when a device starts a play, carrying the item and the profile that
 * played it — the point a scrobbler plugin (Last.fm, Trakt) hooks to report the
 * listen. Subscribe via `Registry::on('playback.recorded', …)`.
 */
class PlaybackRecorded
{
    use PluginEvent;

    /** The stable event name plugins subscribe to. */
    public const NAME = 'playback.recorded';

    public function __construct(
        public readonly MediaItem $item,
        public readonly ?int $profileId = null,
    ) {}
}
