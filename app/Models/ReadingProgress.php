<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A user's place in a book.
 *
 * `location` is written by whichever reader opened the file — an EPUB CFI, a
 * PDF page number, a comic page index — and is only ever interpreted by that
 * same reader. The server treats it as an opaque token.
 */
class ReadingProgress extends Model
{
    protected $table = 'reading_progress';

    protected $fillable = [
        'media_item_id',
        'user_id',
        // Added when profiles arrived, and missed here — so every row was
        // written with a null profile_id no matter who was reading, and
        // `updateOrCreate` keyed on the profile quietly dropped it on insert.
        // A household shared one place in every book, which looks like the
        // app forgetting where you were rather than like a bug.
        'profile_id',
        'location',
        'percent',
        'finished',
    ];

    protected $casts = [
        'percent' => 'integer',
        'finished' => 'boolean',
    ];

    public function mediaItem(): BelongsTo
    {
        return $this->belongsTo(MediaItem::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
