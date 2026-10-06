<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What ffprobe says a file actually contains (#467).
 *
 * Before this, no video technical data was stored anywhere -- which is why
 * keep-best for video compared file sizes, letting a 10% larger file win
 * whatever it held. A 1080p remux and a 4K rip were indistinguishable to the
 * code deciding which to keep.
 */
class MediaProbe extends Model
{
    protected $fillable = [
        'media_item_id', 'probed_at', 'container', 'duration_ms', 'bitrate',
        'size_bytes', 'video_codec', 'width', 'height', 'fps', 'hdr',
        'bit_depth', 'audio_streams', 'subtitle_streams', 'raw',
    ];

    protected $casts = [
        'probed_at' => 'datetime',
        'duration_ms' => 'integer',
        'bitrate' => 'integer',
        'size_bytes' => 'integer',
        'width' => 'integer',
        'height' => 'integer',
        'fps' => 'float',
        'bit_depth' => 'integer',
        'audio_streams' => 'array',
        'subtitle_streams' => 'array',
        'raw' => 'array',
    ];

    public function mediaItem(): BelongsTo
    {
        return $this->belongsTo(MediaItem::class);
    }

    /** Whether this is a video file, as opposed to audio with a sleeve. */
    public function isVideo(): bool
    {
        return $this->video_codec !== null && $this->height !== null;
    }

    /**
     * The vertical resolution tier, as people talk about it.
     *
     * Rounded to the nearest standard rather than reported exactly, because
     * 1920x1038 and 1920x1080 are both "1080p" to anyone choosing between
     * copies -- a cropped aspect ratio is not a lower quality tier.
     */
    public function resolutionLabel(): ?string
    {
        return match (true) {
            $this->height === null => null,
            $this->height >= 2000 => '2160p',
            $this->height >= 1000 => '1080p',
            $this->height >= 700 => '720p',
            $this->height >= 550 => '576p',
            $this->height >= 400 => '480p',
            default => $this->height.'p',
        };
    }

    /** Whether any audio stream exists at all. */
    public function hasAudio(): bool
    {
        return ($this->audio_streams ?? []) !== [];
    }

    /** @return array<int, string> The languages audio is available in. */
    public function audioLanguages(): array
    {
        return collect($this->audio_streams ?? [])
            ->pluck('language')
            ->filter()
            ->unique()
            ->values()
            ->all();
    }
}
