<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Collection extends Model
{
    protected $fillable = ['user_id', 'name', 'description', 'artwork_path'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The playlist cover's public URL, or null when it has none (the client
     * then draws a mosaic of the tracks' covers). Mirrors MediaItem::coverUrl():
     * an absolute URL is returned as-is, a stored path is served from the public
     * disk with each segment url-encoded for spaces/commas in the name.
     */
    public function artworkUrl(): ?string
    {
        $value = $this->artwork_path;

        if (blank($value)) {
            return null;
        }

        if (str_starts_with($value, 'http://') || str_starts_with($value, 'https://')) {
            return $value;
        }

        $encoded = implode('/', array_map('rawurlencode', explode('/', ltrim($value, '/'))));

        return url('storage/' . $encoded);
    }

    public function mediaItems(): BelongsToMany
    {
        return $this->belongsToMany(MediaItem::class, 'collection_media_item')
            ->withPivot('sort_order')
            ->orderByPivot('sort_order');
    }
}
