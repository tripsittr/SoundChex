<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Events;

use App\Events\Concerns\PluginEvent;
use App\Models\MediaItem;

/**
 * A media conversion job has begun for an item.
 *
 * Subscribe via `Registry::on('transcode.started', …)`.
 */
class TranscodeStarted
{
    use PluginEvent;

    public const NAME = 'transcode.started';

    public function __construct(public readonly MediaItem $item) {}
}
