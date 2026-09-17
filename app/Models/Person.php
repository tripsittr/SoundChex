<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Person extends Model
{
    protected $fillable = [
        'name',
        'tmdb_id',
        'musicbrainz_artist_id',
        'headshot_url',
        'image_source',
        'artist_type',
        'country',
        'began',
        'ended',
        'disambiguation',
        'biography',
        'profile_synced_at',
    ];

    protected function casts(): array
    {
        return ['profile_synced_at' => 'datetime'];
    }

    public function mediaItems(): BelongsToMany
    {
        return $this->belongsToMany(MediaItem::class, 'media_item_person')
            ->withPivot(['role', 'character', 'sort_order']);
    }
}
