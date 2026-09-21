<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Events;

use App\Events\Concerns\PluginEvent;
use App\Models\Transfer;

/**
 * A server-to-server library transfer began.
 *
 * Subscribe via `Registry::on('transfer.started', …)`.
 */
class TransferStarted
{
    use PluginEvent;

    public const NAME = 'transfer.started';

    public function __construct(public readonly Transfer $transfer) {}
}
