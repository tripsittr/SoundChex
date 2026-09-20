<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A plugin catalog URL an admin browses to install from (S-264 Phase 4b).
 *
 * Being listed here is not trust — it is only a place to fetch a catalog. The
 * official flag distinguishes the curated first-party repository from ones added
 * at the admin's own risk, which the UI states; every install still passes the
 * checksum and compatibility checks regardless of which repository it came from.
 */
class PluginRepository extends Model
{
    protected $fillable = [
        'name',
        'url',
        'official',
    ];

    protected $casts = [
        'official' => 'boolean',
    ];
}
