<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One ordered chunk of a book's reflowable text (S-295).
 *
 * A chapter (EPUB) or a page (PDF). The reader stitches these in `position`
 * order into a continuous, reflowable book. Extracted by BookTextExtractor and
 * cached; the reader reads, it never parses the file itself.
 */
class BookContent extends Model
{
    protected $fillable = [
        'media_item_id',
        'position',
        'page',
        'title',
        'text',
    ];

    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'page' => 'integer',
        ];
    }

    public function mediaItem(): BelongsTo
    {
        return $this->belongsTo(MediaItem::class);
    }
}
