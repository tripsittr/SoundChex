<?php

namespace App\Filament\Resources\Movies\Pages;

use App\Enums\ProcessingStatus;
use App\Filament\Resources\Movies\MovieResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListMovies extends ListRecords
{
    protected static string $resource = MovieResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Add movie'),
        ];
    }

    public function getTabs(): array
    {
        return [
            'all' => Tab::make('All'),

            'owned' => Tab::make('Owned')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('owned', true)),

            'wishlist' => Tab::make('Wishlist')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('wishlist', true)),

            'needs_review' => Tab::make('Needs review')
                ->badge(fn (): int => MovieResource::getEloquentQuery()
                    ->whereIn('processing_status', [
                        ProcessingStatus::NeedsReview->value,
                        ProcessingStatus::Failed->value,
                    ])
                    ->count())
                ->badgeColor('warning')
                ->modifyQueryUsing(fn (Builder $query) => $query->whereIn('processing_status', [
                    ProcessingStatus::NeedsReview->value,
                    ProcessingStatus::Failed->value,
                ])),
        ];
    }
}
