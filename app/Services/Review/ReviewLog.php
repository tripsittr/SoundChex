<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Services\Review;

use App\Enums\ProcessingStatus;
use App\Enums\ReviewReason;
use App\Enums\SystemReviewReason;
use App\Models\MediaItem;
use App\Models\ReviewItem;
use App\Models\User;
use App\Services\Pipeline\PipelineRunner;

/**
 * The one way something enters or leaves review (#489).
 *
 * Every stage opens its review items through here rather than setting a column
 * of its own. That is the whole point: the audit's "lands nowhere" list existed
 * because each feature added its own flag, so a failure kind with no flag had
 * nowhere to record itself and simply vanished.
 *
 * It also guarantees the invariant that makes the health check meaningful — an
 * item is never hidden from the library without an open review item saying why.
 */
class ReviewLog
{
    public function __construct(private PipelineRunner $runner) {}

    /**
     * Records that the system needs a person to look at something.
     *
     * Idempotent per reason: re-running a stage updates the existing complaint
     * rather than stacking a second copy. That is what stopped the old
     * duplicate flag re-firing on every sweep.
     *
     * @param  array<string, mixed>  $details  What was measured, for the screen.
     * @param  array<string, mixed>  $suggestedAction  The primary action to offer.
     */
    public function open(
        MediaItem $item,
        SystemReviewReason $reason,
        array $details = [],
        array $suggestedAction = [],
    ): ReviewItem {
        $existing = ReviewItem::query()
            ->where('media_item_id', $item->id)
            ->where('reason', $reason->value)
            ->open()
            ->first();

        if ($existing !== null) {
            // Refreshed rather than duplicated. The evidence may have changed
            // -- a quality finding's numbers, a new candidate -- and the
            // reviewer should see the current version.
            $existing->forceFill([
                'details' => $details ?: $existing->details,
                'suggested_action' => $suggestedAction ?: $existing->suggested_action,
            ])->save();

            return $existing;
        }

        return ReviewItem::create([
            'media_item_id' => $item->id,
            'source' => ReviewItem::SOURCE_SYSTEM,
            'reason' => $reason->value,
            'details' => $details,
            'suggested_action' => $suggestedAction,
            'status' => ReviewItem::STATUS_OPEN,
        ]);
    }

    /**
     * Records a user's report.
     *
     * Kept distinct from a system finding: a person saying "the cover is wrong"
     * is evidence of a different kind from a checker measuring a bitrate, and
     * the screen should not present them identically.
     */
    public function report(
        MediaItem $item,
        ReviewReason $reason,
        ?User $user = null,
        ?int $profileId = null,
        ?string $note = null,
    ): ReviewItem {
        return ReviewItem::create([
            'media_item_id' => $item->id,
            'source' => ReviewItem::SOURCE_USER,
            'reason' => $reason->value,
            'user_id' => $user?->id,
            'profile_id' => $profileId,
            'note' => $note,
            'status' => ReviewItem::STATUS_OPEN,
        ]);
    }

    /**
     * Closes an item and puts the media back into the pipeline.
     *
     * The second half is what makes resolving mean something. Closing the row
     * alone would leave the item parked forever: it is `pipeline_state =
     * waiting` that keeps it out of the library, and only a resume clears
     * that.
     *
     * @param  array<string, mixed>  $resolution  What was decided, for the record.
     */
    public function resolve(ReviewItem $reviewItem, ?User $by = null, array $resolution = []): void
    {
        $reviewItem->forceFill([
            'status' => ReviewItem::STATUS_RESOLVED,
            'resolution' => $resolution,
            'resolved_by' => $by?->id,
            'resolved_at' => now(),
        ])->save();

        $this->resumeIfNothingElseIsOpen($reviewItem);
    }

    /**
     * Closes an item without acting on it — "this is fine as it is".
     *
     * Distinct from resolving, because the distinction is the user's answer: a
     * dismissed cover warning means the cover was right all along, and a
     * resolved one means they picked a different image. Recording both as
     * "resolved" would lose which.
     */
    public function dismiss(ReviewItem $reviewItem, ?User $by = null, ?string $note = null): void
    {
        $reviewItem->forceFill([
            'status' => ReviewItem::STATUS_DISMISSED,
            'resolution' => ['dismissed' => true, 'note' => $note],
            'resolved_by' => $by?->id,
            'resolved_at' => now(),
        ])->save();

        // Stamped so nothing drags it back: a re-enrichment that re-flagged a
        // dismissed item is exactly the trap S-302 fixed.
        $this->itemFor($reviewItem)?->forceFill(['reviewed_at' => now()])->saveQuietly();

        $this->resumeIfNothingElseIsOpen($reviewItem);
    }

    /** Everything still open for an item. */
    public function openFor(MediaItem $item)
    {
        return ReviewItem::where('media_item_id', $item->id)->open()->get();
    }

    /**
     * The number that must be zero.
     *
     * An item hidden from the library with nothing open to explain it is
     * invisible to every other report — it is absent from the library *and*
     * absent from review, which is the state this whole phase abolishes.
     * `server:health` asserts it.
     */
    public function hiddenWithNothingOpen(): int
    {
        return MediaItem::withoutGlobalScopes()
            ->where('processing_status', '!=', ProcessingStatus::Complete->value)
            ->whereDoesntHave('reviewItems', fn ($query) => $query->open())
            ->count();
    }

    /**
     * The media item a review item is about.
     *
     * Queried rather than taken from the relation, because a `ReviewItem` just
     * returned by `create()` has nothing loaded and `->mediaItem` is null on
     * it. Unscoped, since anything in review is hidden by `ResolvedScope` by
     * definition -- a scoped lookup would find nothing precisely when it
     * matters.
     */
    private function itemFor(ReviewItem $reviewItem): ?MediaItem
    {
        return MediaItem::withoutGlobalScopes()->find($reviewItem->media_item_id);
    }

    /**
     * Resumes the pipeline once nothing is left open for the item.
     *
     * Only then: an item with two open complaints is not ready to move on
     * because the second one still needs an answer, and resuming would file it
     * while a person was mid-decision.
     */
    private function resumeIfNothingElseIsOpen(ReviewItem $reviewItem): void
    {
        // Loaded explicitly, not read off the relation. A model straight from
        // create() has no relations loaded, and `->mediaItem` on it returns
        // null -- which made resolve() close the row and silently fail to
        // resume, leaving the item parked forever. Unscoped, because every
        // item in review is hidden by ResolvedScope by definition.
        $item = $this->itemFor($reviewItem);

        if ($item === null) {
            return;
        }

        $stillOpen = ReviewItem::where('media_item_id', $item->id)->open()->exists();

        if ($stillOpen) {
            return;
        }

        $this->runner->resumeAt($item, $reviewItem->resumeAt());
    }
}
