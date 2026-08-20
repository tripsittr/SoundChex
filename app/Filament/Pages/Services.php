<?php

namespace App\Filament\Pages;

use App\Filament\Concerns\RestrictsToAdmins;
use App\Services\HostServices;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/**
 * The background processes this host needs running.
 *
 * All three have stopped silently at some point. The web server presents on a
 * phone as a white screen with no explanation; the queue worker's absence is
 * subtler, because the library appears to work while quietly never finishing
 * anything; and without the scheduler nothing is scanned and no backup is ever
 * taken.
 *
 * They belong here rather than in a standalone window: this is where the server
 * is administered, and a second place to look is a second place to forget.
 */
class Services extends Page
{
    use RestrictsToAdmins;

    protected static function requiredPermission(): string
    {
        return 'View:Services';
    }

    protected string $view = 'filament.pages.services';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCog6Tooth;

    protected static string|UnitEnum|null $navigationGroup = 'System';

    protected static ?string $title = 'Services';

    protected static ?string $navigationLabel = 'Services';

    /** @var array<string, array{name: string, hint: string, installed: bool, running: bool, detail: string}> */
    public array $services = [];

    public bool $supported = true;

    public function mount(): void
    {
        $this->refreshServices();
    }

    public function refreshServices(): void
    {
        $host = app(HostServices::class);

        $this->supported = $host->supported();
        $this->services = $this->supported ? $host->all() : [];
    }

    public function startService(string $key): void
    {
        $started = app(HostServices::class)->start($key);

        $this->refreshServices();

        Notification::make()
            ->title($started ? 'Service started' : 'Could not start it')
            ->body($started ? null : 'Check the log for why.')
            ->{$started ? 'success' : 'danger'}()
            ->send();
    }

    public function stopService(string $key): void
    {
        $stopped = app(HostServices::class)->stop($key);

        $this->refreshServices();

        Notification::make()
            ->title($stopped ? 'Service stopped' : 'Could not stop it')
            ->{$stopped ? 'success' : 'danger'}()
            ->send();
    }

    public function installService(string $key): void
    {
        $installed = app(HostServices::class)->install($key);

        $this->refreshServices();

        Notification::make()
            ->title($installed ? 'Service installed' : 'Could not install it')
            ->body($installed
                ? 'It will start on its own from now on, including after a reboot.'
                : 'Check that the plist exists and launchctl is available.')
            ->{$installed ? 'success' : 'danger'}()
            ->send();
    }

    /**
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('refresh')
                ->label('Refresh')
                ->icon(Heroicon::OutlinedArrowPath)
                ->action('refreshServices'),

            Action::make('installAll')
                ->label('Install all')
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->visible(fn (): bool => $this->supported && collect($this->services)
                    ->contains(fn (array $s): bool => ! $s['installed']))
                ->requiresConfirmation()
                ->modalDescription(
                    'Each service will start on its own from now on, including after a reboot. '
                    . 'This is what stops the server dying quietly.',
                )
                ->action(function (): void {
                    foreach (array_keys(HostServices::SERVICES) as $key) {
                        app(HostServices::class)->install($key);
                    }

                    $this->refreshServices();

                    Notification::make()->title('Services installed')->success()->send();
                }),
        ];
    }
}
