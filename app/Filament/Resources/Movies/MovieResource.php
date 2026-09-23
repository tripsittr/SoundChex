<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Filament\Resources\Movies;

use App\Filament\Concerns\RestrictsToAdmins;

use App\Enums\MediaItemType;
use App\Filament\Resources\Concerns\IsMediaTypeResource;
use App\Filament\Resources\Movies\Pages\CreateMovie;
use App\Filament\Resources\Movies\Pages\EditMovie;
use App\Filament\Resources\Movies\Pages\ListMovies;
use App\Filament\Resources\Movies\Schemas\MovieForm;
use App\Filament\Resources\Movies\Tables\MoviesTable;
use App\Models\MediaItem;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class MovieResource extends Resource
{
    use RestrictsToAdmins;

    use IsMediaTypeResource;

    protected static ?string $model = MediaItem::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedFilm;

    protected static string|UnitEnum|null $navigationGroup = 'Media Library';

    protected static ?string $navigationLabel = 'Movies';

    protected static ?string $modelLabel = 'movie';

    protected static ?string $pluralModelLabel = 'movies';

    protected static ?int $navigationSort = 2;

    protected static ?string $recordTitleAttribute = 'title';

    public static function mediaType(): MediaItemType
    {
        return MediaItemType::Movie;
    }

    public static function form(Schema $schema): Schema
    {
        return MovieForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return MoviesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index'  => ListMovies::route('/'),
            'create' => CreateMovie::route('/create'),
            'edit'   => EditMovie::route('/{record}/edit'),
        ];
    }
}
