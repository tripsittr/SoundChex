<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Events;

use App\Events\Concerns\PluginEvent;
use App\Models\Profile;

/**
 * A viewing profile was created.
 *
 * Subscribe via `Registry::on('profile.created', …)`.
 */
class ProfileCreated
{
    use PluginEvent;

    public const NAME = 'profile.created';

    public function __construct(public readonly Profile $profile) {}
}
