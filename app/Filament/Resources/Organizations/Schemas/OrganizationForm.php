<?php

namespace App\Filament\Resources\Organizations\Schemas;

use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class OrganizationForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Grid::make([
                    'default' => 1,
                    'md' => 3,
                ])->schema([
                    Section::make('Organization')
                        ->columnSpan(2)
                        ->schema([
                            Grid::make([
                                'default' => 1,
                                'md' => 2,
                            ])->schema([
                                TextInput::make('name')
                                    ->required()
                                    ->maxLength(255),
                                Select::make('type')
                                    ->label('Organization type')
                                    ->options([
                                        'artist' => 'Artist',
                                        'band' => 'Band',
                                        'record_label' => 'Record label',
                                        'recording_studio' => 'Recording studio',
                                        'venue' => 'Venue',
                                        'promoter' => 'Promoter',
                                        'other' => 'Other',
                                    ]),
                                FileUpload::make('logo')
                                    ->label('Logo')
                                    ->image()
                                    ->directory('organizations')
                                    ->imageEditor()
                                    ->imagePreviewHeight('120')
                                    ->panelAspectRatio('1:1')
                                    ->maxSize(2048),
                                TextInput::make('website')
                                    ->url()
                                    ->maxLength(255),
                            ]),
                        ]),
                    Section::make('Contact')
                        ->columnSpan(1)
                        ->schema([
                            Grid::make([
                                'default' => 1,
                                'md' => 1,
                            ])->schema([
                                TextInput::make('email')
                                    ->label('Email address')
                                    ->email()
                                    ->maxLength(255),
                                TextInput::make('phone')
                                    ->tel()
                                    ->maxLength(255),
                            ]),
                        ]),
                    Section::make('Address')
                        ->columnSpan(2)
                        ->schema([
                            Grid::make([
                                'default' => 1,
                                'md' => 2,
                            ])->schema([
                                TextInput::make('address')
                                    ->columnSpanFull()
                                    ->maxLength(255),
                                TextInput::make('city')
                                    ->maxLength(255),
                                TextInput::make('state')
                                    ->maxLength(255),
                                TextInput::make('zip')
                                    ->label('ZIP / Postal code')
                                    ->maxLength(255),
                                TextInput::make('country')
                                    ->maxLength(255),
                            ]),
                        ]),
                ])->columnSpanFull(),
            ]);
    }
}
