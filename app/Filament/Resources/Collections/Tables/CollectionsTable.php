<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Filament\Resources\Collections\Tables;

use App\Models\Collection;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class CollectionsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable()
                    ->weight('medium')
                    ->description(fn (Collection $record): ?string => $record->description),

                TextColumn::make('media_items_count')
                    ->label('Items')
                    ->counts('mediaItems')
                    ->badge()
                    ->alignCenter()
                    ->sortable(),

                TextColumn::make('user.name')
                    ->label('Created by')
                    ->placeholder('—')
                    ->toggleable(),

                TextColumn::make('created_at')
                    ->label('Created')
                    ->date()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->emptyStateHeading('No collections yet')
            ->emptyStateDescription('Group anything together — a soundtrack, a series marathon, a reading list.')
            ->emptyStateIcon('heroicon-o-rectangle-stack');
    }
}
