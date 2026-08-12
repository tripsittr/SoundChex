<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Collection extends Model
{
    protected $fillable = ['user_id', 'name', 'description'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function mediaItems(): BelongsToMany
    {
        return $this->belongsToMany(MediaItem::class, 'collection_media_item')
            ->withPivot('sort_order')
            ->orderByPivot('sort_order');
    }
}
