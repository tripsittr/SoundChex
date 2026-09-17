<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Where a title can be streamed, rented, or bought right now.
 *
 * A cache of TMDB's watch-provider data rather than a property of the item:
 * licensing moves constantly, so these rows are replaced wholesale on each
 * refresh instead of being merged.
 */
class MediaAvailability extends Model
{
    protected $table = 'media_availability';

    protected $fillable = [
        'media_item_id',
        'provider_slug',
        'provider_name',
        'logo_url',
        'offer_type',
        'region',
    ];

    public function mediaItem(): BelongsTo
    {
        return $this->belongsTo(MediaItem::class);
    }

    /** The brand colour for this provider's tile, from config. */
    public function color(): string
    {
        return config("providers.services.{$this->provider_slug}.color", '#5a5a5a');
    }
}
