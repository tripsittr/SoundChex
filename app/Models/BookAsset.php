<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * An illustration, map or plate extracted from a book file.
 *
 * Only images judged to be content are kept — books carry hundreds of rules
 * and bullets that would otherwise fill the gallery.
 */
class BookAsset extends Model
{
    protected $fillable = [
        'media_item_id',
        'page',
        'path',
        'width',
        'height',
        'bytes',
        'format',
        'is_significant',
        'is_cover',
    ];

    protected $casts = [
        'page' => 'integer',
        'width' => 'integer',
        'height' => 'integer',
        'bytes' => 'integer',
        'is_significant' => 'boolean',
        'is_cover' => 'boolean',
    ];

    public function mediaItem(): BelongsTo
    {
        return $this->belongsTo(MediaItem::class);
    }

    public function absolutePath(): ?string
    {
        if (blank($this->path)) {
            return null;
        }

        $path = Storage::path($this->path);

        return is_file($path) ? $path : null;
    }

    public function exists(): bool
    {
        return $this->absolutePath() !== null;
    }

    /** Portrait images are shown taller in the gallery grid. */
    public function isPortrait(): bool
    {
        return $this->height > $this->width;
    }
}
