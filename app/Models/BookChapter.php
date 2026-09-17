<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One entry from a book's outline.
 *
 * Depth rather than a parent id: the tree is only ever rendered as an indented
 * list, never queried through.
 */
class BookChapter extends Model
{
    protected $fillable = [
        'media_item_id',
        'title',
        'page',
        'depth',
        'sort_order',
    ];

    protected $casts = [
        'page' => 'integer',
        'depth' => 'integer',
        'sort_order' => 'integer',
    ];

    public function mediaItem(): BelongsTo
    {
        return $this->belongsTo(MediaItem::class);
    }
}
