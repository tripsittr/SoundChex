<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Filament\Resources\Shows;

use App\Filament\Concerns\RestrictsToAdmins;

use App\Enums\MediaItemType;
use App\Filament\Resources\Concerns\IsMediaTypeResource;
use App\Filament\Resources\Shows\Pages\CreateShow;
use App\Filament\Resources\Shows\Pages\EditShow;
use App\Filament\Resources\Shows\Pages\ListShows;
use App\Filament\Resources\Shows\Schemas\ShowForm;
use App\Filament\Resources\Shows\Tables\ShowsTable;
use App\Models\MediaItem;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class ShowResource extends Resource
{
    use RestrictsToAdmins;

    use IsMediaTypeResource;

    protected static ?string $model = MediaItem::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTv;

    protected static string|UnitEnum|null $navigationGroup = 'Media Library';

    protected static ?string $navigationLabel = 'TV Shows';

    protected static ?string $modelLabel = 'show';

    protected static ?string $pluralModelLabel = 'shows';

    protected static ?int $navigationSort = 3;

    protected static ?string $recordTitleAttribute = 'title';

    public static function mediaType(): MediaItemType
    {
        return MediaItemType::Show;
    }

    public static function form(Schema $schema): Schema
    {
        return ShowForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ShowsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index'  => ListShows::route('/'),
            'create' => CreateShow::route('/create'),
            'edit'   => EditShow::route('/{record}/edit'),
        ];
    }
}
