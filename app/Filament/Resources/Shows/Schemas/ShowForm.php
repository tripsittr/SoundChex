<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Filament\Resources\Shows\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ShowForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(['default' => 1, 'lg' => 3])
            ->components([
                Section::make('Series')
                    ->description('Add a title and TMDB fills in the rest automatically.')
                    ->columnSpan(['default' => 1, 'lg' => 2])
                    ->columns(['default' => 1, 'md' => 2])
                    ->schema([
                        TextInput::make('title')
                            ->required()
                            ->maxLength(255)
                            ->columnSpanFull(),

                        TextInput::make('showMetadata.creator')
                            ->label('Creator')
                            ->maxLength(255),

                        TextInput::make('showMetadata.network')
                            ->label('Network')
                            ->maxLength(255),

                        TextInput::make('showMetadata.first_air_year')
                            ->label('First aired')
                            ->numeric()
                            ->minValue(1920)
                            ->maxValue(2999),

                        TextInput::make('showMetadata.last_air_year')
                            ->label('Last aired')
                            ->numeric()
                            ->minValue(1920)
                            ->maxValue(2999),

                        Textarea::make('notes')
                            ->label('Overview')
                            ->rows(4)
                            ->columnSpanFull()
                            ->helperText('Left empty, TMDB\'s synopsis is used.'),
                    ]),

                Section::make('Library')
                    ->columnSpan(['default' => 1, 'lg' => 1])
                    ->schema([
                        Select::make('user_rating')
                            ->label('Your rating')
                            ->options(array_combine(range(1, 10), range(1, 10)))
                            ->native(false)
                            ->placeholder('Not rated'),

                        Toggle::make('owned')->label('Owned')->default(true),
                        Toggle::make('wishlist')->label('Wishlist'),

                        Select::make('source_service')
                            ->label('Source')
                            ->options(fn (): array => collect(config('providers.services', []))
                                ->map(fn (array $service): string => $service['name'])
                                ->all())
                            ->searchable()
                            ->native(false)
                            ->placeholder('Not set')
                            // Distinct from streaming availability, which TMDB
                            // fetches automatically — this is where your copy
                            // came from.
                            ->helperText('Where this copy came from.'),
                    ]),

                Section::make('Run')
                    ->columnSpanFull()
                    ->columns(['default' => 1, 'md' => 4])
                    ->collapsed()
                    ->schema([
                        Select::make('showMetadata.status')
                            ->label('Status')
                            ->options([
                                'ongoing'   => 'Ongoing',
                                'ended'     => 'Ended',
                                'cancelled' => 'Cancelled',
                            ])
                            ->native(false),

                        TextInput::make('showMetadata.season_count')
                            ->label('Seasons')
                            ->numeric()
                            ->minValue(0),

                        TextInput::make('showMetadata.episode_count')
                            ->label('Episodes')
                            ->numeric()
                            ->minValue(0),

                        TextInput::make('showMetadata.tmdb_id')
                            ->label('TMDB ID')
                            ->numeric()
                            ->helperText('Pins the lookup to an exact series.'),
                    ]),
            ]);
    }
}
