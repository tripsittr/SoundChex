<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Services\Metadata\Contracts;

use App\Models\MediaItem;

interface MetadataSource
{
    /**
     * Whether this source can enrich the given item.
     * Check type compatibility and whether the required API key is configured.
     */
    public function supports(MediaItem $item): bool;

    /**
     * Enrich the item. Writes directly to the item's metadata record and tags.
     * Must never overwrite fields whose tag has source = 'manual'.
     */
    public function enrich(MediaItem $item): void;

    /**
     * Lower number = runs first in the pipeline.
     */
    public function priority(): int;

    /**
     * Human-readable name shown in the settings UI.
     */
    public function name(): string;

    /**
     * Settings keys this source requires (shown in the settings UI as fields).
     * Keys map to the settings table. Values are the field labels.
     *
     * Example: ['acoustid_api_key' => 'AcoustID Application API Key']
     */
    public function requiredSettings(): array;
}
