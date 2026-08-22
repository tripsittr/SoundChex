<?php

namespace App\Filament\Resources\Movies\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class MovieForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(['default' => 1, 'lg' => 3])
            ->components([
                Section::make('Film')
                    ->description('Add a title and TMDB fills in the rest automatically.')
                    ->columnSpan(['default' => 1, 'lg' => 2])
                    ->columns(['default' => 1, 'md' => 2])
                    ->schema([
                        TextInput::make('title')
                            ->required()
                            ->maxLength(255)
                            ->columnSpanFull(),

                        TextInput::make('movieMetadata.director')
                            ->label('Director')
                            ->maxLength(255),

                        TextInput::make('movieMetadata.studio')
                            ->label('Studio')
                            ->maxLength(255),

                        TextInput::make('movieMetadata.release_year')
                            ->label('Year')
                            ->numeric()
                            ->minValue(1880)
                            ->maxValue(2999)
                            ->helperText('Narrows the lookup when several films share a title.'),

                        TextInput::make('movieMetadata.runtime_minutes')
                            ->label('Runtime')
                            ->numeric()
                            ->suffix('min'),

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

                Section::make('Identifiers')
                    ->description('Set either to pin the lookup to an exact film.')
                    ->columnSpanFull()
                    ->columns(['default' => 1, 'md' => 4])
                    ->collapsed()
                    ->schema([
                        TextInput::make('movieMetadata.tmdb_id')
                            ->label('TMDB ID')
                            ->numeric(),

                        TextInput::make('movieMetadata.imdb_id')
                            ->label('IMDb ID')
                            ->placeholder('tt0083658')
                            ->maxLength(32),

                        TextInput::make('movieMetadata.mpaa_rating')
                            ->label('Rating')
                            ->maxLength(16),

                        TextInput::make('movieMetadata.language')
                            ->label('Language')
                            ->maxLength(16),
                    ]),
            ]);
    }
}
