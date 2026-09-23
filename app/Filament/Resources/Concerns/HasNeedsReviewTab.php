<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Filament\Resources\Concerns;

use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

/**
 * The "Needs review" tab every media list page carries.
 *
 * Shared because the badge count and the tab's own filter must select the same
 * rows: a badge reading 3 over a tab showing 5 is a bug report, and keeping the
 * status list in one place is what stops the two drifting apart.
 *
 * @mixin \Filament\Resources\Pages\ListRecords
 */
trait HasNeedsReviewTab
{
    use HasNeedsReviewStatuses;

    /** The tab, badged with how many items are waiting. */
    protected function needsReviewTab(): Tab
    {
        $statuses = static::needsReviewStatuses();

        return Tab::make('Needs review')
            ->badge(fn (): int => static::getResource()::getEloquentQuery()
                ->whereIn('processing_status', $statuses)
                ->count())
            ->badgeColor('warning')
            ->modifyQueryUsing(fn (Builder $query) => $query->whereIn('processing_status', $statuses));
    }
}
