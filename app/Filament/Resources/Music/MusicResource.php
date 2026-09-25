<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Filament\Resources\Music;

use App\Enums\MediaItemType;
use App\Filament\Concerns\RestrictsToAdmins;
use App\Filament\Resources\Music\Pages\CreateMusic;
use App\Filament\Resources\Music\Pages\EditMusic;
use App\Filament\Resources\Music\Pages\ListMusic;
use App\Filament\Resources\Music\Schemas\MusicForm;
use App\Filament\Resources\Music\Tables\MusicTable;
use App\Models\MediaItem;
use App\Models\Scopes\ResolvedScope;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class MusicResource extends Resource
{
    use RestrictsToAdmins;

    protected static ?string $model = MediaItem::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMusicalNote;

    protected static string|UnitEnum|null $navigationGroup = 'Media Library';

    protected static ?string $navigationLabel = 'Music';

    protected static ?string $modelLabel = 'track';

    protected static ?string $pluralModelLabel = 'music';

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'title';

    /**
     * MediaItem backs every media type, so every query through this resource
     * is constrained to music. Applies to lists, edits, and global search.
     *
     * SoundChex is self-hosted — one server, one library — so there is nothing
     * to scope by beyond media type.
     */
    public static function getEloquentQuery(): Builder
    {
        // The admin panel's job is to *find* the unresolved items, so it
        // opts out of the library's hide-what-is-uncertain scope (S-396).
        return parent::getEloquentQuery()
            ->withoutGlobalScope(ResolvedScope::class)
            ->where('type', MediaItemType::Music)
            ->with('musicMetadata');
    }

    public static function form(Schema $schema): Schema
    {
        return MusicForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return MusicTable::configure($table);
    }

    public static function getGloballySearchableAttributes(): array
    {
        return ['title', 'musicMetadata.artist', 'musicMetadata.album'];
    }

    public static function getGlobalSearchResultDetails($record): array
    {
        return array_filter([
            'Artist' => $record->musicMetadata?->artist,
            'Album' => $record->musicMetadata?->album,
        ]);
    }

    public static function getNavigationBadge(): ?string
    {
        return (string) static::getEloquentQuery()->count();
    }

    public static function getPages(): array
    {
        return [
            'index' => ListMusic::route('/'),
            'create' => CreateMusic::route('/create'),
            'edit' => EditMusic::route('/{record}/edit'),
        ];
    }
}
