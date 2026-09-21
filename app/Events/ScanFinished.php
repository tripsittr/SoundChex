<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Events;

use App\Events\Concerns\PluginEvent;

/**
 * A library scan finished, carrying its counts (imported, duplicates, unsettled).
 *
 * Subscribe via `Registry::on('scan.finished', …)`.
 */
class ScanFinished
{
    use PluginEvent;

    public const NAME = 'scan.finished';

    /** @param array<string, mixed> $result */
    public function __construct(public readonly array $result) {}
}
