<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShowMetadata extends Model
{
    /**
     * A metadata write bumps the parent's `updated_at` (S-386).
     *
     * The device's delta sync asks for items whose `media_items.updated_at`
     * has moved, but almost everything a person edits lives here in the child
     * row — album, artist, rating. Without this the parent timestamp never
     * moved, the delta returned nothing, and a corrected album stayed wrong on
     * every device until the app was reinstalled.
     *
     * @var array<int, string>
     */
    protected $touches = ['mediaItem'];

    protected $fillable = [
        'media_item_id',
        'creator',
        'network',
        'first_air_year',
        'last_air_year',
        'tmdb_id',
        'tvdb_id',
        'tvmaze_id',
        'season_count',
        'episode_count',
        'status',
        'language',
        'season_number',
        'episode_number',
        'episode_title',
        'episode_air_date',
    ];

    public function mediaItem(): BelongsTo
    {
        return $this->belongsTo(MediaItem::class);
    }
}
