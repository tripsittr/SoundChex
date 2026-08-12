<?php

namespace App\Filament\Resources\Profiles;

use App\Filament\Resources\Profiles\Pages\ListProfiles;
use App\Models\Profile;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\FileUpload;
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
}
