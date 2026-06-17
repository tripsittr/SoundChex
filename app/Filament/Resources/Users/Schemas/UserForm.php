<?php

namespace App\Filament\Resources\Users\Schemas;

use Filament\Facades\Filament;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class UserForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Grid::make([
                    'default' => 1,
                    'lg' => 3,
                ])->schema([
                    Section::make('Profile')
                        ->columnSpan(1)
                        ->schema([
                            FileUpload::make('profile_photo_path')
                                ->label('Profile photo')
                                ->disk('public')
                                ->directory('profile-photos')
                                ->image()
                                ->imageEditor()
                                ->circleCropper()
                                ->avatar()
                                ->maxSize(2048),
                            Select::make('type')
                                ->label('User type')
                                ->options(config('user_types.options', []))
                                ->searchable()
                                ->preload()
                                ->native(false)
                                ->helperText('Choose the closest user/team category for this account.'),
                            Placeholder::make('membership_hint')
                                ->label('Team memberships')
                                ->content('Users can belong to one or many organizations. Use the selector in the Account section.'),
                        ]),
                    Section::make('Account')
                        ->columnSpan(2)
                        ->schema([
                            Grid::make([
                                'default' => 1,
                                'md' => 2,
                            ])->schema([
                                TextInput::make('name')
                                    ->required()
                                    ->maxLength(255),
                                TextInput::make('email')
                                    ->label('Email address')
                                    ->email()
                                    ->required()
                                    ->maxLength(255)
                                    ->unique(ignoreRecord: true),
                                Select::make('organizations')
                                    ->label('Organizations / Teams')
                                    ->relationship(
                                        'organizations',
                                        'name',
                                        modifyQueryUsing: function ($query) {
                                            if (! Filament::hasTenancy()) {
                                                return;
                                            }

                                            $tenant = Filament::getTenant();

                                            if (! $tenant) {
                                                return;
                                            }

                                            $query->whereKey($tenant->getKey());
                                        },
                                    )
                                    ->multiple()
                                    ->preload()
                                    ->searchable()
                                    ->helperText('In customer panel this is scoped to the current organization. In admin you can attach multiple teams.'),
                                DateTimePicker::make('email_verified_at')
                                    ->label('Email verified at')
                                    ->seconds(false),
                            ]),
                        ]),
                    Section::make('Security')
                        ->columnSpan(3)
                        ->schema([
                            Grid::make([
                                'default' => 1,
                                'md' => 2,
                            ])->schema([
                                TextInput::make('password')
                                    ->password()
                                    ->revealable()
                                    ->helperText('Leave blank when editing to keep the current password.')
                                    ->required(fn (string $operation): bool => $operation === 'create')
                                    ->dehydrated(fn (?string $state): bool => filled($state))
                                    ->minLength(8)
                                    ->columnSpan(1),
                            ]),
                        ]),
                ]),
            ]);
    }
}
