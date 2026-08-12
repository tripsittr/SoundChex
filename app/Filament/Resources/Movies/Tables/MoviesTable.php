<?php

namespace App\Filament\Resources\Movies\Tables;

use App\Enums\MediaItemType;
use App\Filament\Resources\Concerns\BuildsMediaTable;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class MoviesTable
{
    use BuildsMediaTable;

    /** Required by BuildsMediaTable for the genre filter. */
    public static function mediaType(): MediaItemType
    {
        return MediaItemType::Movie;
    }

    public static function configure(Table $table): Table
    {
        return static::baseTable($table, [
            TextColumn::make('movieMetadata.release_year')
                ->label('Year')
                ->sortable()
                ->toggleable(),

            TextColumn::make('movieMetadata.runtime_minutes')
                ->label('Runtime')
                ->formatStateUsing(fn (?int $state): string => $state ? $state . ' min' : '—')
                ->sortable()
                ->toggleable(),

            TextColumn::make('movieMetadata.mpaa_rating')
                ->label('Rated')
                ->badge()
                ->color('gray')
                ->placeholder('—')
                ->toggleable(),
        ], placeholder: '🎬')
            ->emptyStateHeading('No movies yet')
            ->emptyStateDescription('Add a title and TMDB fills in the rest.')
            ->emptyStateIcon('heroicon-o-film');
    }

    /**
     * @return array<int, \Filament\Actions\Action>
     */
    protected static function extraRecordActions(): array
    {
        return [static::subtitleAction()];
    }
}
