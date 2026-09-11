<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MediaPlay extends Model
{
    protected $fillable = [
        'media_item_id',
        'user_id',
        // Missing here for a while, and silently dropped on every create as a
        // result: the column stayed null, so the "reuse this session's row"
        // lookup — which filters on profile_id — never matched and each
        // position update wrote a new row instead of updating one.
        //
        // Position belongs to the person watching, not the account: two people
        // sharing a login keep separate places in the same film, and that only
        // works if the column is actually written.
        'profile_id',
        'position_seconds',
        // Accumulated listening time, as distinct from the resume bookmark
        // above. Listed here for the same reason `profile_id` had to be: a
        // column missing from this array is silently dropped on create.
        'listened_seconds',
        'completed',
    ];

    protected $casts = [
        'completed' => 'boolean',
        'position_seconds' => 'integer',
        'listened_seconds' => 'integer',
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
