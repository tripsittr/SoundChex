<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Models;

use App\Services\Metadata\AlbumTitleNormalizer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MusicMetadata extends Model
{
    /**
     * A metadata write bumps the parent's `updated_at` (S-386).
     *
     * The device's delta sync asks for items whose `media_items.updated_at`
     * has moved, but almost everything a person edits lives here in the child
     * row — album, artist, rating. Without this the parent timestamp never
     * moved, the delta returned nothing, and a corrected album stayed wrong on
     * every device until the app was reinstalled.
     *
     * @var array<int, string>
     */
    protected $touches = ['mediaItem'];

    /**
     * Keep `album_key` — the canonical grouping key the album browse groups on
     * (S-308) — in step with `album` whenever a row is saved, so a variant
     * spelling ("(Deluxe)", a different bracket style) always groups with its
     * album no matter how or when it was imported.
     */
    protected static function booted(): void
    {
        static::saving(function (self $meta): void {
            if ($meta->isDirty('album') || $meta->album_key === null) {
                $meta->album_key = filled($meta->album)
                    ? app(AlbumTitleNormalizer::class)->canonicalKey((string) $meta->album)
                    : null;
            }
        });
    }

    /**
     * Above this, a "track number" is not one.
     *
     * Physical media tops out well below 100 tracks; the highest legitimate
     * value seen in practice is around 50. Files tagged by some tools carry a
     * library-wide position instead — 1,129 of 1,258 tracks in one real
     * library, with values into the hundreds — and showing those as track
     * numbers puts nonsense in the listing and sorts albums by it.
     */
    private const MAX_PLAUSIBLE_TRACK = 100;

    /**
     * Above this, a "disc number" is not one either.
     *
     * Lower than the track ceiling because box sets are the extreme case and
     * even those stay well under fifty discs; a larger value is the same kind
     * of mis-tagging.
     */
    private const MAX_PLAUSIBLE_DISC = 50;

    /**
     * The track number, or null when the tag is not believable.
     *
     * Read through rather than corrected in the database: the file's tag is
     * what it is, and rewriting the user's files to suit a display decision
     * would be the wrong trade. This keeps the raw value available while
     * making every consumer agree on what counts as usable.
     */
    public function trackNumber(): ?int
    {
        $raw = $this->track_number;

        return $raw !== null && $raw >= 1 && $raw <= self::MAX_PLAUSIBLE_TRACK
            ? (int) $raw
            : null;
    }

    /**
     * Disc numbers get the same treatment, for the same reason.
     */
    public function discNumber(): ?int
    {
        $raw = $this->disc_number;

        return $raw !== null && $raw >= 1 && $raw <= self::MAX_PLAUSIBLE_DISC
            ? (int) $raw
            : null;
    }

    protected $fillable = [
        'media_item_id',
        'artist',
        'primary_artist',
        'album',
        'album_key',
        'track_number',
        'disc_number',
        'release_year',
        'label',
        'bpm',
        'key',
        'scale',
        'duration_ms',
        'sample_rate',
        'bit_depth',
        'format',
        'isrc',
        'musicbrainz_recording_id',
        'musicbrainz_release_id',
        'acoustid',
        'spotify_id',
        'discogs_release_id',
        'energy',
        'lyrics',
        'lyrics_synced',
        'lyrics_checked_at',
    ];

    protected $casts = [
        'bpm' => 'float',
        'energy' => 'integer',
        'lyrics_checked_at' => 'datetime',
    ];

    public function mediaItem(): BelongsTo
    {
        return $this->belongsTo(MediaItem::class);
    }
}
