<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Events;

use App\Events\Concerns\PluginEvent;
use App\Models\MediaItem;

/**
 * A duplicate was detected — a byte-identical copy or the same recording in another file.
 *
 * Subscribe via `Registry::on('duplicate.detected', …)`.
 */
class DuplicateDetected
{
    use PluginEvent;

    public const NAME = 'duplicate.detected';

    public function __construct(public readonly MediaItem $item, public readonly ?MediaItem $original = null) {}
}
