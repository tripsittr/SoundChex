<?php

namespace App\Filament\Pages;

use App\Filament\Concerns\RestrictsToAdmins;

use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use App\Services\SettingsService;
use UnitEnum;

class Settings extends Page
{
    use RestrictsToAdmins;

    protected string $view = 'filament.pages.settings';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCog6Tooth;

    protected static string|UnitEnum|null $navigationGroup = 'Settings';

    protected static ?string $navigationLabel = 'Settings';

    protected static ?string $title = 'Application Settings';

    protected static ?int $navigationSort = 1;

    /**
     * @var array<string, mixed>
     */
    public ?array $data = [];

    public function mount(): void
    {
        $this->form->fill(static::getStoredSettings());
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Section::make('General')
                    ->description('Application-wide preferences.')
                    ->schema([
                        TextInput::make('app_name')
                            ->label('Application name')
                            ->maxLength(255)
                            ->helperText('Display name used across the customer-facing experience.'),
                        Toggle::make('allow_registration')
                            ->label('Allow public registration')
                            ->helperText('When disabled, new accounts can only join via invite.'),
                        Toggle::make('require_email_verification')
                            ->label('Require email verification'),
                    ]),
            ]);
    }

    public function save(): void
    {
        $state = $this->form->getState();

        // Persisted to the settings table rather than the cache: these were
        // previously held in Cache::forever, which `optimize:clear` wipes —
        // silently reverting every preference to its default.
        $settings = app(SettingsService::class);

        foreach ($state as $key => $value) {
            $settings->set(static::settingPrefix() . $key, $value);
        }

        Notification::make()
            ->title('Settings saved')
            ->success()
            ->send();
    }

    protected function getFormActions(): array
    {
        return [
            Action::make('save')
                ->label('Save changes')
                ->submit('save'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function getStoredSettings(): array
    {
        $settings = app(SettingsService::class);

        $defaults = [
            'app_name' => config('app.name'),
            'allow_registration' => true,
            'require_email_verification' => false,
        ];

        $stored = [];

        foreach ($defaults as $key => $default) {
            $value = $settings->get(static::settingPrefix() . $key);

            // A stored `false` is a real choice, so only a genuinely absent
            // value falls back to the default.
            $stored[$key] = $value === null
                ? $default
                : (is_bool($default) ? filter_var($value, FILTER_VALIDATE_BOOLEAN) : $value);
        }

        return $stored;
    }

    /** Namespaces these keys so they can't collide with API keys. */
    protected static function settingPrefix(): string
    {
        return 'app_';
    }
}
