<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Events;

use App\Events\Concerns\PluginEvent;
use App\Models\Collection;

/**
 * A playlist was created.
 *
 * Subscribe via `Registry::on('playlist.created', …)`.
 */
class PlaylistCreated
{
    use PluginEvent;

    public const NAME = 'playlist.created';

    public function __construct(public readonly Collection $playlist, public readonly ?int $profileId = null) {}
}
