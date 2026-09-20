<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * The app's record of one installed plugin (S-264).
 *
 * The source of truth for a plugin's *code* is its folder on disk; this row is
 * the source of truth for whether it is *enabled*. The loader reads this table
 * to decide what to run, so a disabled row is a plugin whose code never loads.
 */
class InstalledPlugin extends Model
{
    protected $fillable = [
        'plugin_id',
        'name',
        'version',
        'enabled',
        'directory',
        'manifest',
    ];

    protected $casts = [
        'enabled' => 'boolean',
        'manifest' => 'array',
    ];

    /** Only the plugins the loader should actually run. */
    public function scopeEnabled($query)
    {
        return $query->where('enabled', true);
    }
}
