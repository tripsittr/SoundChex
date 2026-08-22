<?php

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

            'all' => Tab::make('All'),
        ];
    }

    private static function countByStatus(DuplicateStatus $status): int
    {
        return DuplicateResource::getEloquentQuery()
            ->where('duplicate_status', $status)
            ->count();
    }
}
