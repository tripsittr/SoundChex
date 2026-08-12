<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MusicMetadata extends Model
{
    protected $fillable = [
        'media_item_id',
        'artist',
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
    ];

    protected $casts = [
        'bpm' => 'float',
        'energy' => 'integer',
    ];

    public function mediaItem(): BelongsTo
    {
        return $this->belongsTo(MediaItem::class);
    }
}
