<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BookMetadata extends Model
{
    protected $fillable = [
        'media_item_id',
        'author',
        'publisher',
        'publish_year',
        'isbn_10',
        'isbn_13',
        'open_library_id',
        'google_books_id',
        'pages',
        'language',
        'series_name',
        'series_position',
    ];

    public function mediaItem(): BelongsTo
    {
        return $this->belongsTo(MediaItem::class);
    }
}
