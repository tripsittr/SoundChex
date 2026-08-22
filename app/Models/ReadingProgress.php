<?php

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
