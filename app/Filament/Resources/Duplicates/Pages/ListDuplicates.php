<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Filament\Resources\Duplicates\Pages;

use App\Enums\DuplicateStatus;
use App\Filament\Resources\Concerns\HasNeedsReviewStatuses;
use App\Filament\Resources\Duplicates\DuplicateResource;
use App\Jobs\DetectDuplicatesJob;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListDuplicates extends ListRecords
{
    use HasNeedsReviewStatuses;

    protected static string $resource = DuplicateResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('rescan')
                ->label('Scan for duplicates')
                ->icon('heroicon-o-magnifying-glass')
                ->color('gray')
                // Hashing every catalogued file is slow enough to matter on a
                // large library, so it's queued rather than run in the request.
                ->action(function (): void {
                    DetectDuplicatesJob::dispatch();

                    Notification::make()
                        ->title('Scanning for duplicates')
                        ->body('Files are being compared in the background. Refresh in a moment to see results.')
                        ->success()
                        ->send();
                }),
        ];
    }

    /**
     * Rebuild the table when the tab changes.
     *
     * Each tab is a different *kind* of review with different actions — the
     * Metadata tab re-enriches, the Duplicates tab merges. `DuplicatesTable`
     * switches the whole table config (columns and actions) on the active tab,
     * but Filament's default `updatedActiveTab()` only resets the page, leaving
     * the previous tab's table — and its registered actions — in place. So the
     * Merge action leaked onto the Metadata tab after a visit to Duplicates.
     * `resetTable()` re-runs the table build against the now-current tab, so the
     * actions match the tab that is showing.
     */
    public function updatedActiveTab(): void
    {
        parent::updatedActiveTab();

        $this->resetTable();
    }

    public function getTabs(): array
    {
        // The tabs are the review *types*: Metadata, Duplicates and Cover art
        // (each with a count of what is waiting), then the two resolved states
        // and All. The page as a whole is "Needs Review"; a tab picks what kind.
        return [
            // Items the metadata pipeline could not identify confidently, or that
            // it flagged as an ambiguous match. This is where the "Needs Review"
            // status shown in the music list finally has a home — the two used to
            // be different systems sharing a name (S-277).
            'metadata' => Tab::make('Metadata')
                ->badge(fn (): int => static::countMetadataReview())
                ->badgeColor('warning')
                ->modifyQueryUsing(fn (Builder $query) => $query
                    ->whereIn('processing_status', static::needsReviewStatuses())),

            'pending' => Tab::make('Duplicates')
                ->badge(fn (): int => static::countByStatus(DuplicateStatus::Pending))
                ->badgeColor('warning')
                ->modifyQueryUsing(fn (Builder $query) => $query
                    ->where('duplicate_status', DuplicateStatus::Pending)),

            // Merged rows whose two copies had different cover art. The audio is
            // resolved; the kept cover just needs a human's eye (S-265).
            'cover' => Tab::make('Cover art')
                ->badge(fn (): int => static::countCoverReview())
                ->badgeColor('info')
                ->modifyQueryUsing(fn (Builder $query) => $query
                    ->where('needs_cover_review', true)),

            'kept' => Tab::make('Keeping both')
                ->modifyQueryUsing(fn (Builder $query) => $query
                    ->where('duplicate_status', DuplicateStatus::Kept)),

            'merged' => Tab::make('Merged')
                ->modifyQueryUsing(fn (Builder $query) => $query
                    ->where('duplicate_status', DuplicateStatus::Merged)),

            'all' => Tab::make('All'),
        ];
    }

    private static function countByStatus(DuplicateStatus $status): int
    {
        return DuplicateResource::getEloquentQuery()
            ->where('duplicate_status', $status)
            ->count();
    }

    private static function countCoverReview(): int
    {
        return DuplicateResource::getEloquentQuery()
            ->where('needs_cover_review', true)
            ->count();
    }

    private static function countMetadataReview(): int
    {
        return DuplicateResource::getEloquentQuery()
            ->whereIn('processing_status', static::needsReviewStatuses())
            ->count();
    }
}
