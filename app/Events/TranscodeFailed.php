<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Events;

use App\Events\Concerns\PluginEvent;
use App\Models\MediaItem;

/**
 * A media conversion failed for an item.
 *
 * Subscribe via `Registry::on('transcode.failed', …)`.
 */
class TranscodeFailed
{
    use PluginEvent;

    public const NAME = 'transcode.failed';

    public function __construct(public readonly MediaItem $item, public readonly ?string $reason = null) {}
}
