<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Filament\Resources\Users\Schemas;

use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class UserForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns([
                'default' => 1,
                'lg' => 3,
            ])
            ->components([
                Section::make('Profile')
                    ->columnSpan(['default' => 1, 'lg' => 1])
                    ->schema([
                        FileUpload::make('profile_photo_path')
                            ->label('Photo')
                            ->disk('public')
                            ->directory('profile-photos')
                            ->image()
                            ->imageEditor()
                            ->circleCropper()
                            ->avatar()
                            ->maxSize(2048),

                        Select::make('type')
                            ->label('Role')
                            ->options(config('user_types.options', []))
                            ->searchable()
                            ->preload()
                            ->native(false)
                            ->helperText('Owners and admins manage the library; members browse it.'),
                    ]),

                Section::make('Account')
                    ->columnSpan(['default' => 1, 'lg' => 2])
                    ->columns(['default' => 1, 'md' => 2])
                    ->schema([
                        TextInput::make('name')
                            ->required()
                            ->maxLength(255),

                        TextInput::make('email')
                            ->label('Email address')
                            ->email()
                            ->required()
                            ->maxLength(255)
                            ->unique(ignoreRecord: true),

                        DateTimePicker::make('email_verified_at')
                            ->label('Email verified')
                            ->seconds(false)
                            ->helperText('Leave empty to mark this account unverified.'),

                        TextInput::make('password')
                            ->password()
                            ->revealable()
                            ->minLength(8)
                            ->required(fn (string $operation): bool => $operation === 'create')
                            // Without this an empty field would blank the stored
                            // password on every edit.
                            ->dehydrated(fn (?string $state): bool => filled($state))
                            ->helperText('Leave blank to keep the current password.'),
                    ]),
            ]);
    }
}
