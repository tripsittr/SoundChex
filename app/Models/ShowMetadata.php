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
        // Shows get the same scores as films: OMDb answers for a series by
        // IMDb id, and a show with no ratings beside a film that has them
        // reads as a broken page rather than a gap in the data (#504).
        'imdb_id',
        'imdb_rating',
        'rt_score',
        'awards',
        'metascore',
    ];

    /**
     * Typed, so a client is not handed "7.5" where a film gives 7.5. The
     * movie table has always cast these; this one had no casts at all.
     */
    protected $casts = [
        'imdb_rating' => 'float',
        'rt_score' => 'float',
        'metascore' => 'integer',
    ];

    public function mediaItem(): BelongsTo
    {
        return $this->belongsTo(MediaItem::class);
    }
}
