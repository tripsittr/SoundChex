<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Events;

use App\Events\Concerns\PluginEvent;

/**
 * A scheduled server health check ran — disk, queue depth, failed jobs, scan age. Carries the metrics and whether anything is wrong.
 *
 * Subscribe via `Registry::on('server.healthChecked', …)`.
 */
class ServerHealthChecked
{
    use PluginEvent;

    public const NAME = 'server.healthChecked';

    /** @param array<string, mixed> $metrics */
    public function __construct(public readonly array $metrics, public readonly bool $healthy) {}
}
