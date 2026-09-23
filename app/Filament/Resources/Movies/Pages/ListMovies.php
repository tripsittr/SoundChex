<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Filament\Resources\Movies\Pages;

use App\Filament\Resources\Concerns\HasNeedsReviewTab;
use App\Filament\Resources\Movies\MovieResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListMovies extends ListRecords
{
    use HasNeedsReviewTab;

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

            'needs_review' => $this->needsReviewTab(),
        ];
    }
}
