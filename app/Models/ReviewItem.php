<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Models;

use App\Enums\PipelineStage;
use App\Enums\ReviewReason;
use App\Enums\SystemReviewReason;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One thing that needs a person (#469).
 *
 * Replaces review-as-a-status. Five columns and a reports table could record
 * THAT something was wrong; none of them could record *why*, *what the
 * evidence was*, or *what to do about it* -- so a new failure kind had nowhere
 * to go and went nowhere.
 */
class ReviewItem extends Model
{
    public const SOURCE_SYSTEM = 'system';

    public const SOURCE_USER = 'user';

    public const STATUS_OPEN = 'open';

    public const STATUS_RESOLVED = 'resolved';

    public const STATUS_DISMISSED = 'dismissed';

    protected $fillable = [
        'media_item_id', 'source', 'reason', 'user_id', 'profile_id',
        'note', 'details', 'suggested_action', 'status', 'resolution',
        'resolved_by', 'resolved_at', 'snoozed_until',
    ];

    protected $casts = [
        'details' => 'array',
        'suggested_action' => 'array',
        'resolution' => 'array',
        'resolved_at' => 'datetime',
        'snoozed_until' => 'datetime',
    ];

    public function mediaItem(): BelongsTo
    {
        return $this->belongsTo(MediaItem::class);
    }

    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_OPEN);
    }

    /**
     * Open, and not put off until later.
     *
     * Separate from `open()` on purpose. A snoozed item is still open -- the
     * question stands and `hiddenWithNothingOpen()` must keep counting it, or
     * skipping something would make it vanish from the library *and* from
     * review, which is precisely the state this whole rebuild abolished. It is
     * only hidden from the queue a person is working through.
     */
    public function scopeDueNow(Builder $query): Builder
    {
        return $query->open()->where(fn (Builder $q) => $q
            ->whereNull('snoozed_until')
            ->orWhere('snoozed_until', '<=', now()));
    }

    /** Puts the question off without answering it. */
    public function snooze(\DateTimeInterface $until): void
    {
        $this->forceFill(['snoozed_until' => $until])->save();
    }

    public function isSnoozed(): bool
    {
        return $this->snoozed_until !== null && $this->snoozed_until->isFuture();
    }

    /**
     * The reason, as whichever enum it belongs to.
     *
     * Not a cast, because `source` decides the type: a system reason has
     * thirteen cases and a user reason has the five every client already
     * shows. Merging them into one enum would break the apps.
     */
    public function reasonEnum(): SystemReviewReason|ReviewReason|null
    {
        return $this->source === self::SOURCE_SYSTEM
            ? SystemReviewReason::tryFrom((string) $this->reason)
            : ReviewReason::tryFrom((string) $this->reason);
    }

    public function label(): string
    {
        return $this->reasonEnum()?->label() ?? ucfirst(str_replace('_', ' ', (string) $this->reason));
    }

    /** Which of the review screen's four jobs this belongs to. */
    public function job(): string
    {
        $reason = $this->reasonEnum();

        if ($reason instanceof SystemReviewReason) {
            return $reason->job();
        }

        // A user's report maps onto the same four jobs, so the screen does not
        // need a fifth for "things people complained about".
        return match ($reason) {
            ReviewReason::Duplicate => 'duplicates',
            ReviewReason::Cover => 'covers',
            ReviewReason::File => 'files',
            default => 'identify',
        };
    }

    /**
     * Where the pipeline should resume when this is resolved.
     *
     * Resolving is not just closing: picking a candidate means the item needs
     * enriching again, while accepting a quality finding means it only needs
     * filing. Resuming at the right stage is the difference between a second
     * of work and re-running everything.
     */
    public function resumeAt(): PipelineStage
    {
        $reason = $this->reasonEnum();

        return $reason instanceof SystemReviewReason
            ? $reason->resumeAt()
            : PipelineStage::Identified;
    }

    /** Whether this should hide the item from the library while open. */
    public function hidesItem(): bool
    {
        $reason = $this->reasonEnum();

        return $reason instanceof SystemReviewReason && $reason->hidesItem();
    }

    public function isOpen(): bool
    {
        return $this->status === self::STATUS_OPEN;
    }
}
