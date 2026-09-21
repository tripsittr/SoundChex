<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Events;

use App\Events\Concerns\PluginEvent;
use App\Models\MediaItem;

/**
 * An item was removed from the library (row deleted). Carries the item so a plugin can clean up or sync an external list; the file may already be gone.
 *
 * Subscribe via `Registry::on('media.deleted', …)`.
 */
class MediaItemDeleted
{
    use PluginEvent;

    public const NAME = 'media.deleted';

    public function __construct(public readonly MediaItem $item) {}
}
