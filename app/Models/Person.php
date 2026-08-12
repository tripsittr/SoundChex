<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Person extends Model
{
    protected $fillable = ['name', 'tmdb_id', 'musicbrainz_artist_id', 'headshot_url'];

    public function mediaItems(): BelongsToMany
    {
        return $this->belongsToMany(MediaItem::class, 'media_item_person')
            ->withPivot(['role', 'character', 'sort_order']);
    }
}
