<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Events;

use App\Events\Concerns\PluginEvent;
use App\Models\DeviceReport;

/**
 * A device sent a diagnostic report (a crash, a failed navigation) — the hook a monitoring plugin needs.
 *
 * Subscribe via `Registry::on('device.reported', …)`.
 */
class DeviceReported
{
    use PluginEvent;

    public const NAME = 'device.reported';

    public function __construct(public readonly DeviceReport $report) {}
}
