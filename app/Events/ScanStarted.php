<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Events;

use App\Events\Concerns\PluginEvent;

/**
 * A library scan has begun.
 *
 * Subscribe via `Registry::on('scan.started', …)`.
 */
class ScanStarted
{
    use PluginEvent;

    public const NAME = 'scan.started';

    /** @param array<int, string> $folders */
    public function __construct(public readonly array $folders) {}
}
