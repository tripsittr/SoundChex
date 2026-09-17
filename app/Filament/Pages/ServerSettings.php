<?php

namespace App\Filament\Pages;

use App\Filament\Concerns\RestrictsToServerAdmins;
use App\Services\EnvironmentFile;
use App\Services\NetworkAddresses;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/**
 * The handful of settings that live in `.env` rather than the settings table,
 * editable from the panel instead of a terminal.
 *
 * `APP_URL` and `APP_ENV` are read by the framework at boot, so they cannot be
 * held in the database — they genuinely belong in `.env`. The docs told
 * self-hosters to edit that by hand and run `config:clear`, which is a poor
 * experience for someone who installed an app, and `APP_URL` has a chicken-and-
 * egg: it is the address the server advertises, and you cannot set it from a
 * page reached *via* that address. This page breaks the egg — the panel is
 * reachable on localhost whatever `APP_URL` says — and writes the file plus a
 * `config:clear` itself.
 *
 * The machine's own detected addresses are offered for `APP_URL`, so setting it
 * is usually a click rather than remembering a Tailscale hostname.
 */
class ServerSettings extends Page
{
    use RestrictsToServerAdmins;

    protected string $view = 'filament.pages.server-settings';

    protected static ?string $slug = 'server-settings';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedServerStack;

    protected static string|UnitEnum|null $navigationGroup = 'System';

    protected static ?string $title = 'Server Settings';

    protected static ?string $navigationLabel = 'Server Settings';

    protected static ?int $navigationSort = 15;

    /** @var array<string, mixed> */
    public ?array $data = [];

    public function mount(): void
    {
        $env = app(EnvironmentFile::class);

        $this->form->fill([
            'app_url' => $env->get('APP_URL') ?? config('app.url'),
            'app_env' => $env->get('APP_ENV') ?? config('app.env'),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Section::make('Address')
                    ->description("The address this server advertises to devices, port included. Wrong or portless, and phones can't find it.")
                    ->schema([
                        Select::make('app_url')
                            ->label('Server address (APP_URL)')
                            ->helperText('Pick one of this machine\'s detected addresses, or type another (e.g. a public tunnel).')
                            ->options($this->addressOptions())
                            ->searchable()
                            ->allowHtml(false)
                            // A custom address the machine did not detect is
                            // still valid — a Funnel hostname, a reverse proxy.
                            ->createOptionForm([
                                TextInput::make('custom')
                                    ->label('Address')
                                    ->url()
                                    ->required(),
                            ])
                            ->getOptionLabelUsing(fn (string $value): string => $value),
                    ]),

                Section::make('Environment')
                    ->description('Set to production once the server is reachable from the internet: HTTPS URL generation on, detailed error pages off.')
                    ->schema([
                        Select::make('app_env')
                            ->label('Environment (APP_ENV)')
                            ->options([
                                'local' => 'local — detailed errors, for setup',
                                'production' => 'production — errors hidden, HTTPS URLs',
                            ])
                            ->native(false),
                    ]),
            ]);
    }

    /** This machine's detected addresses, as APP_URL options. */
    private function addressOptions(): array
    {
        $detected = app(NetworkAddresses::class)->detected();

        // detected() returns bare addresses; APP_URL wants a scheme.
        $options = [];
        foreach ($detected as $address) {
            $url = str_starts_with($address, 'http') ? $address : 'http://' . $address;
            $options[$url] = $url;
        }

        // Always include the current value so it is selected even if it is not a
        // detected one (a tunnel hostname, say).
        $current = app(EnvironmentFile::class)->get('APP_URL') ?? config('app.url');
        if ($current) {
            $options[$current] = $current;
        }

        return $options;
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('detect')
                ->label('Auto-detect address')
                ->icon(Heroicon::OutlinedMagnifyingGlass)
                ->color('gray')
                ->action('detect'),

            Action::make('save')
                ->label('Save')
                ->action('save'),
        ];
    }

    /** Fills APP_URL with this machine's best detected address. */
    public function detect(): void
    {
        $best = app(NetworkAddresses::class)->detected()[0] ?? null;

        if ($best === null) {
            Notification::make()->title('No address detected')->warning()->send();

            return;
        }

        $this->data['app_url'] = $best;

        Notification::make()
            ->title('Detected ' . $best)
            ->body('Review it and press Save to apply.')
            ->success()
            ->send();
    }

    public function save(): void
    {
        $data = $this->form->getState();

        try {
            app(EnvironmentFile::class)->set([
                'APP_URL' => $data['app_url'] ?: null,
                'APP_ENV' => $data['app_env'] ?: null,
            ]);
        } catch (\Throwable $e) {
            Notification::make()
                ->title('Could not save')
                ->body($e->getMessage())
                ->danger()
                ->send();

            return;
        }

        Notification::make()
            ->title('Server settings saved')
            ->body('The config cache was cleared, so the change is live.')
            ->success()
            ->send();
    }
}
