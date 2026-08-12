<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * One caption track on a film or episode.
 *
 * Always stored as WebVTT, whatever it arrived as — it's the only subtitle
 * format browsers play natively, so conversion happens once on import rather
 * than on every playback.
 */
class Subtitle extends Model
{
    /** Muxed into the video file itself. */
    public const SOURCE_EMBEDDED = 'embedded';

    /** A .srt/.vtt/.ass sitting next to the video. */
    public const SOURCE_SIDECAR = 'sidecar';

    /** Fetched from OpenSubtitles. */
    public const SOURCE_ONLINE = 'opensubtitles';

    protected $fillable = [
        'media_item_id',
        'language',
        'label',
        'source',
        'path',
        'origin',
        'forced',
        'sdh',
        'is_default',
        'cue_count',
    ];

    protected $casts = [
        'forced' => 'boolean',
        'sdh' => 'boolean',
        'is_default' => 'boolean',
        'cue_count' => 'integer',
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

    /**
     * The name shown in the track picker.
     *
     * Qualifiers are appended rather than baked into the label so the same
     * language reads consistently: "English", "English (SDH)", "English
     * (Forced)".
     */
    public function displayLabel(): string
    {
        $suffixes = array_filter([
            $this->forced ? 'Forced' : null,
            $this->sdh ? 'SDH' : null,
        ]);

        return $suffixes === []
            ? $this->label
            : $this->label . ' (' . implode(', ', $suffixes) . ')';
    }
}
