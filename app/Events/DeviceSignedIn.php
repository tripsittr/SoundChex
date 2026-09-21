<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Events;

use App\Events\Concerns\PluginEvent;

/**
 * A device signed in — an access token was issued for a profile.
 *
 * Subscribe via `Registry::on('device.signedIn', …)`.
 */
class DeviceSignedIn
{
    use PluginEvent;

    public const NAME = 'device.signedIn';

    public function __construct(public readonly string $deviceName, public readonly ?int $profileId = null) {}
}
