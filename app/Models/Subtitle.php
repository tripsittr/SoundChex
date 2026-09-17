<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Services\Subtitles\SubtitleConverter;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
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

    public function cues(): HasMany
    {
        return $this->hasMany(SubtitleCue::class);
    }

    /**
     * Indexes this track's dialogue so it can be searched.
     *
     * Called from every import path rather than duplicated in each: embedded
     * extraction, sidecar import and OpenSubtitles download all end up here,
     * so a track can never be playable but unsearchable.
     *
     * Replaces rather than appends — re-importing a track must not double its
     * cues.
     *
     * @return int Cues indexed.
     */
    public function indexCues(): int
    {
        $path = $this->absolutePath();

        if ($path === null) {
            return 0;
        }

        $parsed = app(SubtitleConverter::class)->parseCues((string) file_get_contents($path));

        $this->cues()->delete();

        if ($parsed === []) {
            return 0;
        }

        // Chunked: a feature film is roughly 1,500 cues, and SQLite has a hard
        // limit on variables per statement.
        foreach (array_chunk($parsed, 200) as $chunk) {
            SubtitleCue::insert(array_map(fn (array $cue): array => [
                'subtitle_id' => $this->id,
                'media_item_id' => $this->media_item_id,
                'start_seconds' => $cue['start'],
                'end_seconds' => $cue['end'],
                'text' => $cue['text'],
                'created_at' => now(),
                'updated_at' => now(),
            ], $chunk));
        }

        return count($parsed);
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
