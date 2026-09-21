<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Events;

use App\Events\Concerns\PluginEvent;
use App\Models\Transfer;

/**
 * A server-to-server library transfer finished.
 *
 * Subscribe via `Registry::on('transfer.completed', …)`.
 */
class TransferCompleted
{
    use PluginEvent;

    public const NAME = 'transfer.completed';

    public function __construct(public readonly Transfer $transfer) {}
}
