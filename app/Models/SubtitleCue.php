<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line of dialogue, at the moment it is spoken.
 *
 * Exists so a phrase can be searched and jumped to. Playback still reads the
 * .vtt file directly — this is an index, not the source of truth.
 */
class SubtitleCue extends Model
{
    protected $fillable = [
        'subtitle_id',
        'media_item_id',
        'start_seconds',
        'end_seconds',
        'text',
    ];

    protected $casts = [
        'start_seconds' => 'float',
        'end_seconds' => 'float',
    ];

    public function subtitle(): BelongsTo
    {
        return $this->belongsTo(Subtitle::class);
    }

    public function mediaItem(): BelongsTo
    {
        return $this->belongsTo(MediaItem::class);
    }

    /** "1:24:03" — how someone reads a position in a film. */
    public function timestamp(): string
    {
        $seconds = (int) $this->start_seconds;

        return $seconds >= 3600
            ? sprintf('%d:%02d:%02d', intdiv($seconds, 3600), intdiv($seconds % 3600, 60), $seconds % 60)
            : sprintf('%d:%02d', intdiv($seconds, 60), $seconds % 60);
    }
}
