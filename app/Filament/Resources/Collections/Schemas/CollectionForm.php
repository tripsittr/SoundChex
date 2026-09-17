<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Filament\Resources\Collections\Schemas;

use App\Models\MediaItem;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;

class CollectionForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(['default' => 1, 'lg' => 3])
            ->components([
                Section::make('Collection')
                    ->columnSpan(['default' => 1, 'lg' => 1])
                    ->schema([
                        TextInput::make('name')
                            ->required()
                            ->maxLength(255)
                            ->placeholder('Halloween night'),

                        Textarea::make('description')
                            ->rows(4)
                            ->maxLength(1000),
                    ]),

                Section::make('Items')
                    ->description('Mix any media types — music, movies, shows, and books.')
                    ->columnSpan(['default' => 1, 'lg' => 2])
                    ->schema([
                        Select::make('mediaItems')
                            ->label('')
                            ->relationship('mediaItems', 'title')
                            ->multiple()
                            ->searchable()
                            ->preload()
                            // A large library makes the plain title ambiguous,
                            // so each option carries its type and subtitle.
                            ->getOptionLabelFromRecordUsing(fn (MediaItem $record): string => sprintf(
                                '%s %s%s',
                                $record->typeGlyph(),
                                $record->title,
                                $record->subtitle() ? ' — ' . $record->subtitle() : '',
                            ))
                            ->getSearchResultsUsing(fn (string $search): array => MediaItem::query()
                                ->where('title', 'like', "%{$search}%")
                                ->orWhereHas('musicMetadata', fn (Builder $q) => $q
                                    ->where('artist', 'like', "%{$search}%"))
                                ->with(['musicMetadata', 'movieMetadata', 'showMetadata', 'bookMetadata'])
                                ->limit(40)
                                ->get()
                                ->mapWithKeys(fn (MediaItem $item) => [
                                    $item->id => sprintf(
                                        '%s %s%s',
                                        $item->typeGlyph(),
                                        $item->title,
                                        $item->subtitle() ? ' — ' . $item->subtitle() : '',
                                    ),
                                ])
                                ->all())
                            ->columnSpanFull(),
                    ]),
            ]);
    }
}
