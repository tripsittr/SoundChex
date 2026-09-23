<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Filament\Resources\Shows\Pages;

use App\Filament\Resources\Concerns\HasNeedsReviewTab;
use App\Filament\Resources\Shows\ShowResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListShows extends ListRecords
{
    use HasNeedsReviewTab;

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

            'needs_review' => $this->needsReviewTab(),
        ];
    }
}
