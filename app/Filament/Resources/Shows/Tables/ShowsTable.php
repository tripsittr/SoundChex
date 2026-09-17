<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Filament\Resources\Shows\Tables;

use App\Enums\MediaItemType;
use App\Filament\Resources\Concerns\BuildsMediaTable;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ShowsTable
{
    use BuildsMediaTable;

    /** Required by BuildsMediaTable for the genre filter. */
    public static function mediaType(): MediaItemType
    {
        return MediaItemType::Show;
    }

    public static function configure(Table $table): Table
    {
        return static::baseTable($table, [
            TextColumn::make('showMetadata.network')
                ->label('Network')
                ->placeholder('—')
                ->toggleable(),

            TextColumn::make('showMetadata.first_air_year')
                ->label('Aired')
                ->formatStateUsing(function (?int $state, $record): string {
                    $last = $record->showMetadata?->last_air_year;

                    if (! $state) {
                        return '—';
                    }

                    // A run that ended in a different year reads as a range.
                    return $last && $last !== $state ? "{$state}–{$last}" : (string) $state;
                })
                ->sortable()
                ->toggleable(),

            TextColumn::make('showMetadata.season_count')
                ->label('Seasons')
                ->badge()
                ->color('gray')
                ->alignCenter()
                ->placeholder('—')
                ->toggleable(),

            TextColumn::make('showMetadata.status')
                ->label('Run')
                ->badge()
                ->formatStateUsing(fn (?string $state): string => $state ? ucfirst($state) : '—')
                ->color(fn (?string $state): string => match ($state) {
                    'ongoing' => 'success',
                    'ended' => 'gray',
                    'cancelled' => 'danger',
                    default => 'gray',
                })
                ->toggleable(),
        ], placeholder: '📺')
            ->emptyStateHeading('No shows yet')
            ->emptyStateDescription('Add a title and TMDB fills in the rest.')
            ->emptyStateIcon('heroicon-o-tv');
    }

    /**
     * @return array<int, \Filament\Actions\Action>
     */
    protected static function extraRecordActions(): array
    {
        return [static::subtitleAction()];
    }
}
