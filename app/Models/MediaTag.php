<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Models;

use App\Enums\MediaTagSource;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MediaTag extends Model
{
    protected $fillable = ['media_item_id', 'type', 'value', 'source'];

    protected $casts = [
        'source' => MediaTagSource::class,
    ];

    public function mediaItem(): BelongsTo
    {
        return $this->belongsTo(MediaItem::class);
    }
}
