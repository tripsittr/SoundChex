<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Events;

use App\Events\Concerns\PluginEvent;

/**
 * A required PHP extension is missing on the server — a config fault a monitoring plugin should surface.
 *
 * Subscribe via `Registry::on('server.extensionMissing', …)`.
 */
class ServerExtensionMissing
{
    use PluginEvent;

    public const NAME = 'server.extensionMissing';

    /** @param array<int, string> $missing */
    public function __construct(public readonly array $missing) {}
}
