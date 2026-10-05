<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Models;

use App\Enums\DuplicateStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A ruling on one pair of files: these two have been looked at.
 *
 * The point of recording it per pair rather than on the file is that the file
 * stays searchable. A decision about A and B says nothing about A and C, and
 * before this the scanner had no way to tell those apart — so it skipped A
 * entirely and never found C.
 *
 * Stored lowest id first. The scanner's choice of which copy is "the original"
 * depends on where each file sits at the time and changes when one is filed
 * into the library; a decision that moved with it would be no decision at all.
 */
class DuplicateDecision extends Model
{
    /** `decided_at` is the only time that means anything here. */
    public $timestamps = false;

    protected $fillable = [
        'lower_item_id',
        'higher_item_id',
        'decision',
        'decided_at',
    ];

    protected function casts(): array
    {
        return [
            'decision' => DuplicateStatus::class,
            'decided_at' => 'datetime',
        ];
    }

    public function lowerItem(): BelongsTo
    {
        return $this->belongsTo(MediaItem::class, 'lower_item_id');
    }

    public function higherItem(): BelongsTo
    {
        return $this->belongsTo(MediaItem::class, 'higher_item_id');
    }

    /**
     * Record a ruling on a pair, replacing any earlier one.
     *
     * Replacing rather than erroring because a pair can legitimately be ruled
     * on twice: keep both, then later merge them after all.
     */
    public static function record(int $itemId, int $otherId, DuplicateStatus $decision): void
    {
        if ($itemId === $otherId) {
            return;
        }

        static::updateOrCreate(
            [
                'lower_item_id' => min($itemId, $otherId),
                'higher_item_id' => max($itemId, $otherId),
            ],
            [
                'decision' => $decision,
                'decided_at' => now(),
            ],
        );
    }

    /**
     * The ids this item has already been ruled against.
     *
     * @return list<int>
     */
    public static function partnersOf(int $itemId): array
    {
        return static::query()
            ->where('lower_item_id', $itemId)
            ->orWhere('higher_item_id', $itemId)
            ->get(['lower_item_id', 'higher_item_id'])
            ->map(fn (self $row): int => $row->lower_item_id === $itemId
                ? $row->higher_item_id
                : $row->lower_item_id)
            ->all();
    }

    /** Whether this pair has been ruled on already. */
    public static function existsFor(int $itemId, int $otherId): bool
    {
        return static::query()
            ->where('lower_item_id', min($itemId, $otherId))
            ->where('higher_item_id', max($itemId, $otherId))
            ->exists();
    }
}
