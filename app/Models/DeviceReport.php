<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * What a device said went wrong.
 *
 * A phone has no console anyone can reach, so a failed navigation is a white
 * flash and nothing else. These arrive on their own rather than being read
 * aloud from a diagnostic panel.
 */
class DeviceReport extends Model
{
    protected $fillable = [
        'device',
        'name',
        'kind',
        'ip',
        'platform',
        'build',
        'shell',
        'app_version',
        'origin',
        'events',
    ];

    protected $casts = [
        'events' => 'array',
    ];

    /**
     * Drops what nobody will read.
     *
     * These are for diagnosing something happening now. A month-old report from
     * a build that no longer exists is noise, and an unbounded table fed by
     * every device grows without limit.
     */
    public static function prune(int $days = 14): int
    {
        return static::where('created_at', '<', now()->subDays($days))->delete();
    }
}
