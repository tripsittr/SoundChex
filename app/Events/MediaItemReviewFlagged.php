<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Events;

use App\Events\Concerns\PluginEvent;
use App\Models\MediaItem;

/**
 * The metadata pipeline flagged an item as needing a human look — an ambiguous or missing match.
 *
 * Subscribe via `Registry::on('media.reviewFlagged', …)`.
 */
class MediaItemReviewFlagged
{
    use PluginEvent;

    public const NAME = 'media.reviewFlagged';

    public function __construct(public readonly MediaItem $item, public readonly ?string $source = null) {}
}
