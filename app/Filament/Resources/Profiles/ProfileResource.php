<?php

namespace App\Filament\Resources\Profiles;

use App\Filament\Concerns\RestrictsToAdmins;

use App\Filament\Resources\Profiles\Pages\ListProfiles;
use App\Models\Profile;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/**
 * Viewing profiles.
 *
 * Managed here as well as from the picker: setting a photo or a rating cap is
 * administration, and the picker should stay a one-tap "who's watching?"
 * screen rather than growing a settings form.
 */
class ProfileResource extends Resource
{
    use RestrictsToAdmins;

    protected static ?string $model = Profile::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static string|UnitEnum|null $navigationGroup = 'Settings';

    protected static ?string $navigationLabel = 'Profiles';

    protected static ?int $navigationSort = 3;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Profile')
                ->schema([
                    TextInput::make('name')
                        ->required()
                        ->maxLength(40),

                    FileUpload::make('avatar_path')
                        ->label('Photo')
                        ->image()
                        ->avatar()
                        ->imageEditor()
                        // Square, because every tile that shows it is square.
                        ->imageCropAspectRatio('1:1')
                        ->imageResizeTargetWidth('400')
                        ->imageResizeTargetHeight('400')
                        // The public disk: avatars are decoration, requested on
                        // every page, and routing each through PHP would cost a
                        // request per tile for nothing.
                        ->disk('public')
                        ->directory('avatars')
                        ->maxSize(4096)
                        ->helperText('Optional. Without one, the profile shows its initial on the colour below.'),

                    Select::make('color')
                        ->label('Colour')
                        ->options(array_combine(Profile::COLORS, [
                            'Indigo', 'Pink', 'Amber', 'Emerald',
                            'Blue', 'Violet', 'Red', 'Teal',
                        ]))
                        ->default(Profile::COLORS[0])
                        ->native(false)
                        ->required(),
                ])
                ->columns(2),

            Section::make('Permissions')
                ->description('What this person may do in the admin panel. The household shares one login, so this is where capability is decided — an account cannot be the boundary when everyone signs in with it.')
                ->schema([
                    Toggle::make('is_owner')
                        ->label('Household owner')
                        ->helperText('Full rights, always. There is no way to remove the last owner — a household with nobody able to grant permissions would need fixing in the database.')
                        ->disabled(fn (?Profile $record): bool => $record?->isOwner() ?? false)
                        ->live(),

                    CheckboxList::make('permissions')
                        ->relationship('permissions', 'name')
                        // Friendly labels and an ordering that puts the two
                        // tiers that matter first, so the owner is choosing
                        // "Library administration" rather than reading
                        // `Access:LibraryAdministration` off a raw list.
                        ->options(fn (): array => self::permissionOptions())
                        ->descriptions(self::permissionDescriptions())
                        ->searchable()
                        ->bulkToggleable()
                        ->columns(1)
                        ->helperText('Nothing is granted by default. Library administration lets a profile into the management panel; server administration adds the machine — services, transfers, network, acquisition. Profiles with neither stay in the media center.')
                        ->hidden(fn (Get $get): bool => (bool) $get('is_owner')),

                    TextInput::make('pin')
                        ->label('PIN')
                        ->password()
                        ->revealable()
                        ->numeric()
                        ->minLength(4)
                        ->maxLength(6)
                        ->dehydrated(false)
                        ->helperText('Required to switch into this profile. Anyone on the account can otherwise pick it from the menu and inherit whatever it can do — set one on any profile with permissions.')
                        ->placeholder(fn (?Profile $record): string => $record?->requiresPin() ? 'Set — type to replace' : 'Not set')
                        ->afterStateUpdated(fn (?string $state, ?Profile $record) => filled($state) && $record?->setPin($state)),
                ])
                ->columns(1),

            Section::make('Restrictions')
                ->description('Profiles are a convenience, not a login. The admin panel enforces its own permissions — these settings shape what the media center shows.')
                ->schema([
                    Toggle::make('is_kids')
                        ->label('Kids profile')
                        ->helperText('Hides library management and applies the rating limit below.')
                        ->live(),

                    Select::make('max_rating')
                        ->label('Highest allowed rating')
                        ->options(array_combine(Profile::RATING_ORDER, Profile::RATING_ORDER))
                        ->placeholder('No limit')
                        ->native(false)
                        ->helperText('Titles above this are hidden everywhere, including by direct link. Unrated titles — most music and books — are always allowed.')
                        // Offered for any profile: an adult may want a cap too,
                        // and hiding the control behind the kids toggle would
                        // make that impossible.
                        ->default(fn (Get $get): ?string => $get('is_kids') ? 'PG' : null),
                ])
                ->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('sort_order')
            ->columns([
                ImageColumn::make('avatar_path')
                    ->label('')
                    ->disk('public')
                    ->circular()
                    // Falls back to a coloured initial, matching the app.
                    ->defaultImageUrl(fn (Profile $record): string => static::initialAvatar($record)),

                TextColumn::make('name')
                    ->searchable()
                    ->sortable()
                    ->weight('medium'),

                TextColumn::make('user.name')
                    ->label('Account')
                    ->toggleable(),

                IconColumn::make('is_kids')
                    ->label('Kids')
                    ->boolean(),

                TextColumn::make('max_rating')
                    ->label('Rating limit')
                    ->badge()
                    ->color('warning')
                    ->placeholder('No limit'),

                TextColumn::make('watchlist_count')
                    ->label('My List')
                    ->counts('watchlist')
                    ->alignEnd()
                    ->toggleable(),

                TextColumn::make('last_used_at')
                    ->label('Last used')
                    ->since()
                    ->placeholder('Never')
                    ->sortable()
                    ->toggleable(),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make()
                    // An account with no profile has nowhere to land.
                    ->before(function (Profile $record, DeleteAction $action): void {
                        if (Profile::where('user_id', $record->user_id)->count() <= 1) {
                            Notification::make()
                                ->title('This is the account\'s only profile')
                                ->body('Create another before removing this one.')
                                ->danger()
                                ->send();

                            $action->cancel();
                        }
                    }),
            ])
            ->emptyStateHeading('No profiles yet')
            ->emptyStateDescription('One is created automatically the first time someone opens the media center.');
    }

    /**
     * A data URI showing the initial on the profile's colour.
     *
     * Inline SVG rather than a generated file: it costs no storage and no
     * request, and it matches what the media center draws.
     */
    private static function initialAvatar(Profile $record): string
    {
        $svg = sprintf(
            '<svg xmlns="http://www.w3.org/2000/svg" width="64" height="64">'
            . '<rect width="64" height="64" rx="32" fill="%s"/>'
            . '<text x="50%%" y="50%%" dy=".35em" text-anchor="middle" '
            . 'font-family="sans-serif" font-size="28" font-weight="600" fill="#fff">%s</text></svg>',
            htmlspecialchars($record->color, ENT_QUOTES),
            htmlspecialchars($record->initial(), ENT_QUOTES),
        );

        return 'data:image/svg+xml;base64,' . base64_encode($svg);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListProfiles::route('/'),
        ];
    }

    /** Creating happens on the picker, where the person choosing is present. */
    public static function canCreate(): bool
    {
        return true;
    }

    protected static function mutateFormDataBeforeCreate(array $data): array
    {
        $data['user_id'] ??= auth()->id();

        return $data;
    }

    /**
     * Readable names for the permission checkboxes, keyed by permission id.
     *
     * A relationship CheckboxList keys its options by the related model's id,
     * so these must too. The two administration tiers get proper names and
     * lead; anything else keeps its raw permission name rather than vanishing,
     * because hiding a grantable permission is worse than an ugly label.
     *
     * @return array<int, string>
     */
    protected static function permissionOptions(): array
    {
        $labels = self::permissionLabels();

        return \App\Models\Profile::query()->getConnection()
            ->table('permissions')
            ->orderByRaw(self::permissionOrdering())
            ->pluck('name', 'id')
            ->map(fn (string $name): string => $labels[$name] ?? $name)
            ->all();
    }

    /**
     * Helper text under each checkbox, keyed by permission id.
     *
     * @return array<int, string>
     */
    protected static function permissionDescriptions(): array
    {
        $text = [
            \App\Models\Profile::LIBRARY_ADMINISTRATION =>
                'Into the management panel: metadata, uploads, library settings, statistics and the catalogue.',
            \App\Models\Profile::SERVER_ADMINISTRATION =>
                'The machine underneath: services, server transfer, network and acquisition. Implies library administration.',
        ];

        return \App\Models\Profile::query()->getConnection()
            ->table('permissions')
            ->pluck('name', 'id')
            ->map(fn (string $name): ?string => $text[$name] ?? null)
            ->filter()
            ->all();
    }

    /** @return array<string, string> */
    private static function permissionLabels(): array
    {
        return [
            \App\Models\Profile::LIBRARY_ADMINISTRATION => 'Library administration',
            \App\Models\Profile::SERVER_ADMINISTRATION => 'Server administration',
        ];
    }

    /** The two tiers first, then everything else by name. */
    private static function permissionOrdering(): string
    {
        $lib = \App\Models\Profile::LIBRARY_ADMINISTRATION;
        $srv = \App\Models\Profile::SERVER_ADMINISTRATION;

        return "CASE name WHEN '{$lib}' THEN 0 WHEN '{$srv}' THEN 1 ELSE 2 END, name";
    }
}
