<?php

namespace App\Filament\Pages;

use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Cache;
use UnitEnum;

class Settings extends Page
{
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

        Cache::forever(static::settingsKey(), $state);

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
        return Cache::get(static::settingsKey(), [
            'app_name' => config('app.name'),
            'allow_registration' => true,
            'require_email_verification' => false,
        ]);
    }

    protected static function settingsKey(): string
    {
        return 'app.settings';
    }
}
