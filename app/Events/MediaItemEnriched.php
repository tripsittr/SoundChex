<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Events;

use App\Events\Concerns\PluginEvent;
use App\Models\MediaItem;

/**
 * Enrichment has finished for an item (S-264 Phase 3).
 *
 * Fired after the metadata pipeline and its follow-up steps have run, whatever
 * the outcome — the point a plugin hooks to act on a freshly-enriched item (push
 * it somewhere, derive extra data). Subscribe via
 * `Registry::on('media.enriched', …)`.
 */
class MediaItemEnriched
{
    use PluginEvent;

    /** The stable event name plugins subscribe to. */
    public const NAME = 'media.enriched';

    public function __construct(public readonly MediaItem $item) {}
}
