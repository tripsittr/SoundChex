<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MovieMetadata extends Model
{
    protected $fillable = [
        'media_item_id',
        'director',
        'studio',
        'release_year',
        'runtime_minutes',
        'tmdb_id',
        'imdb_id',
        'language',
        'country',
        'mpaa_rating',
        'tagline',
        'imdb_rating',
        'rt_score',
    ];

    protected $casts = [
        'imdb_rating' => 'float',
        'rt_score' => 'float',
    ];

    public function mediaItem(): BelongsTo
    {
        return $this->belongsTo(MediaItem::class);
    }
}
