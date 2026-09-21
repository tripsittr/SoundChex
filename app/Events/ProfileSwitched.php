<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Events;

use App\Events\Concerns\PluginEvent;
use App\Models\Profile;

/**
 * The active viewing profile changed.
 *
 * Subscribe via `Registry::on('profile.switched', …)`.
 */
class ProfileSwitched
{
    use PluginEvent;

    public const NAME = 'profile.switched';

    public function __construct(public readonly Profile $profile) {}
}
