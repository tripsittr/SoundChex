<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MusicMetadata extends Model
{
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

        return $raw !== null && $raw >= 1 && $raw <= 50 ? (int) $raw : null;
    }

    protected $fillable = [
        'media_item_id',
        'artist',
        'primary_artist',
        'album',
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
