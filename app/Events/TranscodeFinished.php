<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Events;

use App\Events\Concerns\PluginEvent;
use App\Models\MediaItem;

/**
 * A browser-playable converted copy was produced for an item (converted_path set).
 *
 * Subscribe via `Registry::on('transcode.finished', …)`.
 */
class TranscodeFinished
{
    use PluginEvent;

    public const NAME = 'transcode.finished';

    public function __construct(public readonly MediaItem $item, public readonly string $convertedPath) {}
}
