<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Events;

use App\Events\Concerns\PluginEvent;
use App\Models\MediaItem;

/**
 * A content-match duplicate was resolved by keeping one copy and deleting the other.
 *
 * Subscribe via `Registry::on('duplicate.resolved', …)`.
 */
class DuplicateResolved
{
    use PluginEvent;

    public const NAME = 'duplicate.resolved';

    public function __construct(public readonly MediaItem $item) {}
}
