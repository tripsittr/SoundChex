<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line in the unified activity/audit timeline (S-284).
 *
 * Written by the bundled audit-log plugin for each event it observes. The row is
 * self-contained — actor and subject names are denormalised onto it — so the
 * timeline reads correctly even after the profile or item it refers to is gone,
 * which matters because some of what it records is exactly that (a deletion).
 *
 * There is no `updated_at`: an entry is written once and never changed. That is
 * the point of an audit log.
 */
class AuditLogEntry extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'audit_log_entries';

    protected $fillable = [
        'event', 'summary',
        'profile_id', 'actor_name',
        'subject_id', 'subject_type', 'subject_title',
        'context',
    ];

    protected function casts(): array
    {
        return [
            'context' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /**
     * The profile that acted, when there was one. May be null (a scan, a health
     * check) or point at a since-deleted profile — read `actor_name` for a label
     * that survives either.
     */
    public function profile(): BelongsTo
    {
        return $this->belongsTo(Profile::class);
    }
}
