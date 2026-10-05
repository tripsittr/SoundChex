<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Filament\Resources\Concerns;

use App\Enums\DuplicateStatus;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

/**
 * The "Needs review" tab every media list page carries.
 *
 * Shared because the badge count and the tab's own filter must select the same
 * rows: a badge reading 3 over a tab showing 5 is a bug report, and keeping the
 * status list in one place is what stops the two drifting apart.
 *
 * @mixin ListRecords
 */
trait HasNeedsReviewTab
{
    use HasNeedsReviewStatuses;

    /** The tab, badged with how many items are waiting. */
    protected function needsReviewTab(): Tab
    {
        return Tab::make('Needs review')
            ->badge(fn (): int => static::needsReviewQuery(static::getResource()::getEloquentQuery())->count())
            ->badgeColor('warning')
            ->modifyQueryUsing(fn (Builder $query) => static::needsReviewQuery($query));
    }

    /**
     * Everything waiting on a person: an item the pipeline could not identify,
     * and one it flagged as a copy of something already in the library.
     *
     * A pending duplicate is a decision nobody has taken — the detector says
     * two files are the same and is asking which to keep. It was only visible
     * on the Duplicates screen, so a duplicate film or episode sat there
     * unnoticed while the review queue it belongs in reported nothing to do.
     *
     * `Pending` and `Kept` only. A `Merged` duplicate has been dealt with, and
     * including it would put thousands of settled rows into the queue.
     */
    protected static function needsReviewQuery(Builder $query): Builder
    {
        return $query->where(function (Builder $q): void {
            $q->whereIn('processing_status', static::needsReviewStatuses())
                ->orWhere(function (Builder $duplicate): void {
                    $duplicate
                        ->whereNotNull('duplicate_of_id')
                        ->whereIn('duplicate_status', [
                            DuplicateStatus::Pending->value,
                            DuplicateStatus::Kept->value,
                        ]);
                });
        });
    }
}
