<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace SoundChex\PlaylistPorter\Models;

use App\Models\Collection;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A playlist port, tracked from start to finish (S-310).
 */
class PlaylistImport extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_COMPLETE = 'complete';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'user_id',
        'source',
        'source_format',
        'name',
        'status',
        'total_tracks',
        'matched_tracks',
        'unmatched',
        'collection_id',
        'error',
    ];

    protected $casts = [
        'unmatched' => 'array',
        'total_tracks' => 'integer',
        'matched_tracks' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function collection(): BelongsTo
    {
        return $this->belongsTo(Collection::class);
    }
}
