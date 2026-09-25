<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Models;

use App\Enums\ReviewReason;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Someone said an item is wrong (S-398).
 *
 * Raised from the apps, settled in the admin panel. The item itself is hidden
 * from the library the moment a report is raised — see
 * `MediaItem::sendForReview()` — so this record is the only account of *why*
 * it went away, and the admin's only way back to a decision.
 *
 * @property int $id
 * @property int $media_item_id
 * @property ReviewReason $reason
 * @property ?string $note
 */
class MediaItemReport extends Model
{
    protected $fillable = [
        'media_item_id',
        'user_id',
        'profile_id',
        'reason',
        'note',
        'resolved_at',
        'dismissed_at',
    ];

    protected function casts(): array
    {
        return [
            'reason' => ReviewReason::class,
            'resolved_at' => 'datetime',
            'dismissed_at' => 'datetime',
        ];
    }

    public function mediaItem(): BelongsTo
    {
        // Unscoped deliberately: a reported item is hidden from the library by
        // definition (S-396), so the default scope would make every report's
        // own item null — and a report you cannot open is no use to anyone.
        return $this->belongsTo(MediaItem::class)->withoutGlobalScopes();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(Profile::class);
    }

    /** Still waiting on someone. */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNull('resolved_at')->whereNull('dismissed_at');
    }

    public function isOpen(): bool
    {
        return $this->resolved_at === null && $this->dismissed_at === null;
    }

    /** Marks the problem real and dealt with. */
    public function resolve(): void
    {
        $this->forceFill(['resolved_at' => now(), 'dismissed_at' => null])->save();
    }

    /** Marks it as nothing to fix — a mis-tap, or a disagreement about taste. */
    public function dismiss(): void
    {
        $this->forceFill(['dismissed_at' => now(), 'resolved_at' => null])->save();
    }

    /** Who to credit, for the admin table. */
    public function reporterName(): string
    {
        return $this->profile?->name
            ?? $this->user?->name
            ?? 'Someone';
    }
}
