<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Filament\Resources\Shows\Pages;

use App\Enums\ProcessingStatus;
use App\Filament\Resources\Shows\ShowResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListShows extends ListRecords
{
    protected static string $resource = ShowResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Add show'),
        ];
    }

    public function getTabs(): array
    {
        return [
            'all' => Tab::make('All'),

            'ongoing' => Tab::make('Ongoing')
                ->modifyQueryUsing(fn (Builder $query) => $query->whereHas(
                    'showMetadata',
                    fn (Builder $q) => $q->where('status', 'ongoing'),
                )),

            'wishlist' => Tab::make('Wishlist')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('wishlist', true)),

            'needs_review' => Tab::make('Needs review')
                ->badge(fn (): int => ShowResource::getEloquentQuery()
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
