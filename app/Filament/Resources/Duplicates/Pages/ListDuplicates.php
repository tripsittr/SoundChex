<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Filament\Resources\Duplicates\Pages;

use App\Enums\DuplicateStatus;
use App\Filament\Resources\Duplicates\DuplicateResource;
use App\Jobs\DetectDuplicatesJob;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListDuplicates extends ListRecords
{
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

    public function getTabs(): array
    {
        return [
            'pending' => Tab::make('Needs review')
                ->badge(fn (): int => static::countByStatus(DuplicateStatus::Pending))
                ->badgeColor('warning')
                ->modifyQueryUsing(fn (Builder $query) => $query
                    ->where('duplicate_status', DuplicateStatus::Pending)),

            'kept' => Tab::make('Keeping both')
                ->modifyQueryUsing(fn (Builder $query) => $query
                    ->where('duplicate_status', DuplicateStatus::Kept)),

            'merged' => Tab::make('Merged')
                ->modifyQueryUsing(fn (Builder $query) => $query
                    ->where('duplicate_status', DuplicateStatus::Merged)),

            // Merged rows whose two copies had different cover art. The audio is
            // resolved; the kept cover just needs a human's eye (S-265).
            'cover' => Tab::make('Verify cover art')
                ->badge(fn (): int => static::countCoverReview())
                ->badgeColor('info')
                ->modifyQueryUsing(fn (Builder $query) => $query
                    ->where('needs_cover_review', true)),

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
}
