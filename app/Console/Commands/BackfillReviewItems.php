<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Console\Commands;

use App\Enums\MatchConfidence;
use App\Enums\MediaItemType;
use App\Enums\ProcessingStatus;
use App\Enums\SystemReviewReason;
use App\Models\MediaItem;
use App\Services\Review\ReviewLog;
use Illuminate\Console\Command;

/**
 * Gives every already-hidden item a reason (#489).
 *
 * Measured before writing this: **96 items are hidden from the library with
 * nothing open to explain why** -- absent from the library and absent from
 * review at once, which is the exact state this phase abolishes. They predate
 * `review_items`, so the pipeline's own reasons were never recorded for them.
 *
 * Each reason is derived from the columns that *were* set, so this is reading
 * history rather than guessing: `pipeline_error` where the pipeline wrote one,
 * then the cover flag, the duplicate flag, the confidence, and a missing album.
 */
class BackfillReviewItems extends Command
{
    protected $signature = 'library:backfill-review
        {--dry-run : Report what would be opened without writing}';

    protected $description = 'Give every hidden item an open review item saying why';

    public function handle(ReviewLog $log): int
    {
        $hidden = MediaItem::withoutGlobalScopes()
            ->with(['musicMetadata', 'reviewItems'])
            ->where('processing_status', '!=', ProcessingStatus::Complete->value)
            ->whereDoesntHave('reviewItems', fn ($query) => $query->open())
            ->get();

        if ($hidden->isEmpty()) {
            $this->info('Every hidden item already has an open review item. Nothing to do.');

            return self::SUCCESS;
        }

        $this->info($hidden->count().' item(s) are hidden with nothing explaining why.');

        $counts = [];

        foreach ($hidden as $item) {
            $reason = $this->reasonFor($item);
            $counts[$reason->value] = ($counts[$reason->value] ?? 0) + 1;

            if (! $this->option('dry-run')) {
                $log->open($item, $reason, $this->detailsFor($item, $reason));
            }
        }

        $this->newLine();
        $this->table(
            ['reason', 'items'],
            collect($counts)->map(fn (int $n, string $reason): array => [
                SystemReviewReason::from($reason)->label(),
                $n,
            ])->values()->all(),
        );

        if ($this->option('dry-run')) {
            $this->comment('Nothing was written. Re-run without --dry-run to apply.');

            return self::SUCCESS;
        }

        $remaining = $log->hiddenWithNothingOpen();

        $this->info($remaining === 0
            ? 'Every hidden item now says why. server:health will report zero.'
            : "{$remaining} still have nothing open -- worth looking at by hand.");

        return self::SUCCESS;
    }

    /**
     * The best reason the stored columns support.
     *
     * Ordered most specific first, so an item with several possible
     * explanations gets the one that actually tells somebody what to do.
     */
    private function reasonFor(MediaItem $item): SystemReviewReason
    {
        // What the pipeline itself recorded, where it recorded anything. That
        // text was written for a person, so it is the best evidence there is.
        $error = strtolower((string) $item->pipeline_error);

        if (str_contains($error, 'not there') || str_contains($error, 'no file path')) {
            return SystemReviewReason::MissingFile;
        }

        if (str_contains($error, 'cannot be read') || str_contains($error, 'could not read')) {
            return SystemReviewReason::Unreadable;
        }

        if (str_contains($error, 'could not file')) {
            return SystemReviewReason::MoveFailed;
        }

        if (str_contains($error, 'gave up after')) {
            return SystemReviewReason::Stuck;
        }

        if ($item->needs_cover_review) {
            return SystemReviewReason::CoverUncertain;
        }

        if ($item->isPendingDuplicate()) {
            return SystemReviewReason::Duplicate;
        }

        // A music track with no album is the single largest category on this
        // library, and it is a question only a person can answer: a single
        // that never had one, or a tag that failed to read (S-384).
        if ($item->type === MediaItemType::Music && blank($item->musicMetadata?->album)) {
            return SystemReviewReason::MissingAlbum;
        }

        return match ($item->match_confidence) {
            MatchConfidence::Exact => SystemReviewReason::CompilationOrUndated,
            MatchConfidence::Fuzzy => SystemReviewReason::LowConfidence,
            default => SystemReviewReason::NoMatch,
        };
    }

    /**
     * Evidence the screen can show without re-deriving it.
     *
     * @return array<string, mixed>
     */
    private function detailsFor(MediaItem $item, SystemReviewReason $reason): array
    {
        $details = [
            'backfilled' => true,
            'confidence' => $item->match_confidence?->value,
        ];

        if (filled($item->pipeline_error)) {
            $details['pipeline_error'] = $item->pipeline_error;
        }

        if ($reason === SystemReviewReason::Duplicate && $item->duplicate_of_id !== null) {
            $details['duplicate_of'] = $item->duplicate_of_id;
            $details['matched_by'] = $item->duplicate_match?->value;
        }

        return $details;
    }
}
