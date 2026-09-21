<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Events;

use App\Events\Concerns\PluginEvent;
use App\Models\MediaItem;

/**
 * A profile set or changed an item's rating.
 *
 * Subscribe via `Registry::on('user.rated', …)`.
 */
class UserRated
{
    use PluginEvent;

    public const NAME = 'user.rated';

    public function __construct(public readonly MediaItem $item, public readonly ?int $rating, public readonly ?int $profileId = null) {}
}
