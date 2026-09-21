<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Events;

use App\Events\Concerns\PluginEvent;

/**
 * A device signed out — its access token was revoked.
 *
 * Subscribe via `Registry::on('device.signedOut', …)`.
 */
class DeviceSignedOut
{
    use PluginEvent;

    public const NAME = 'device.signedOut';

    public function __construct(public readonly ?string $deviceName = null) {}
}
