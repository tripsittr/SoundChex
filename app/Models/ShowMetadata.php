<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShowMetadata extends Model
{
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
