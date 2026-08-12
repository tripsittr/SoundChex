<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MediaPlay extends Model
{
    protected $fillable = [
        'media_item_id',
        'user_id',
        'position_seconds',
        'completed',
    ];

    protected $casts = [
        'completed' => 'boolean',
        'position_seconds' => 'integer',
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
