<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Events;

use App\Events\Concerns\PluginEvent;
use App\Models\Collection;

/**
 * A playlist was deleted.
 *
 * Subscribe via `Registry::on('playlist.deleted', …)`.
 */
class PlaylistDeleted
{
    use PluginEvent;

    public const NAME = 'playlist.deleted';

    public function __construct(public readonly Collection $playlist, public readonly ?int $profileId = null) {}
}
