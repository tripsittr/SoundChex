<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Events;

use App\Events\Concerns\PluginEvent;

/**
 * Someone ran a library search — the hook for analytics or a 'popular searches' plugin.
 *
 * Subscribe via `Registry::on('user.searched', …)`.
 */
class UserSearched
{
    use PluginEvent;

    public const NAME = 'user.searched';

    public function __construct(public readonly string $query, public readonly int $resultCount, public readonly ?int $profileId = null) {}
}
