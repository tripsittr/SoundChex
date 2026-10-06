<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Models;

use App\Enums\QualitySeverity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Something wrong with a file (#467).
 *
 * Nothing checked quality before this: a truncated file, a CAM rip, a
 * transcoded "FLAC" and a file with no audio stream all imported as healthy,
 * and the owner found out when they tried to play one.
 *
 * A finding records what was measured and what it was measured against, so it
 * explains itself without re-running the check -- which matters because the
 * expensive checks decode the whole file.
 */
class QualityFinding extends Model
{
    protected $fillable = [
        'media_item_id', 'check', 'severity', 'value', 'threshold', 'detail',
    ];

    protected $casts = [
        'severity' => QualitySeverity::class,
        'detail' => 'array',
    ];

    public function mediaItem(): BelongsTo
    {
        return $this->belongsTo(MediaItem::class);
    }

    /** Whether this finding should stop the file being published. */
    public function isBlocking(): bool
    {
        return $this->severity === QualitySeverity::Bad;
    }
}
