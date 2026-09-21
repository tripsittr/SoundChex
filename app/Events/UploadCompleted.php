<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Events;

use App\Events\Concerns\PluginEvent;

/**
 * A bulk upload finished queuing its files for cataloguing.
 *
 * Subscribe via `Registry::on('upload.completed', …)`.
 */
class UploadCompleted
{
    use PluginEvent;

    public const NAME = 'upload.completed';

    public function __construct(public readonly int $count) {}
}
