<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Events;

use App\Events\Concerns\PluginEvent;
use App\Models\Notification;

/**
 * An in-app notification was recorded — the single choke point every notification (scan finished, episode added, and future ones) passes through.
 *
 * Subscribe via `Registry::on('notification.recorded', …)`.
 */
class NotificationRecorded
{
    use PluginEvent;

    public const NAME = 'notification.recorded';

    public function __construct(public readonly Notification $notification) {}
}
