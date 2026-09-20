<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MediaPlay extends Model
{
    /**
     * The surfaces a play can be started from (S-120). The client sends one of
     * these; anything else is recorded as null rather than trusted, so the
     * column stays a small, known set that statistics can group on.
     *
     * @var list<string>
     */
    public const SOURCES = [
        'album', 'artist', 'playlist', 'search', 'home', 'browse', 'show', 'queue',
    ];

    protected $fillable = [
        'media_item_id',
        'user_id',
        'source',
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

    /** The profile that was watching — position and history belong to it. */
    public function profile(): BelongsTo
    {
        return $this->belongsTo(Profile::class);
    }
}
