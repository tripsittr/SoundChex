<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Events;

use App\Events\Concerns\PluginEvent;
use App\Models\Profile;

/**
 * A viewing profile was deleted.
 *
 * Subscribe via `Registry::on('profile.deleted', …)`.
 */
class ProfileDeleted
{
    use PluginEvent;

    public const NAME = 'profile.deleted';

    public function __construct(public readonly Profile $profile) {}
}
