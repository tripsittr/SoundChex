<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Events;

use App\Events\Concerns\PluginEvent;
use App\Models\MediaItem;

/**
 * An item's title was rewritten during enrichment (an artist stripped, a case change).
 *
 * Subscribe via `Registry::on('metadata.titleTidied', …)`.
 */
class MetadataTitleTidied
{
    use PluginEvent;

    public const NAME = 'metadata.titleTidied';

    public function __construct(public readonly MediaItem $item, public readonly string $was, public readonly string $now) {}
}
