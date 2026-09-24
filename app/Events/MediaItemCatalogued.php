<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Events;

use App\Events\Concerns\PluginEvent;
use App\Models\MediaItem;

/**
 * A file has just entered the library (S-264 Phase 3).
 *
 * Fired the moment a scan catalogues a new item, before enrichment runs — the
 * point a plugin hooks to react to something arriving. Part of the named-event
 * surface plugins subscribe to through `Registry::on('media.catalogued', …)`.
 */
class MediaItemCatalogued
{
    use PluginEvent;

    /** The stable event name plugins subscribe to. */
    public const NAME = 'media.catalogued';

    public function __construct(public readonly MediaItem $item) {}
}
