<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Events;

use App\Events\Concerns\PluginEvent;
use App\Models\MediaItem;

/**
 * A duplicate was merged into the copy that was kept.
 *
 * Subscribe via `Registry::on('duplicate.merged', …)`.
 */
class DuplicateMerged
{
    use PluginEvent;

    public const NAME = 'duplicate.merged';

    public function __construct(public readonly MediaItem $item, public readonly ?MediaItem $original = null) {}
}
