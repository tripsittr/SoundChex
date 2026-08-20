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

    /** When the state on screen was read, so a refresh visibly did something. */
    public ?string $checkedAt = null;

    /** Which service's log is open, if any. */
    public ?string $showingLog = null;

    public string $logContents = '';

    public function mount(): void
    {
        $this->refreshServices();
    }

    public function refreshServices(bool $quiet = true): void
    {
        $host = app(HostServices::class);

        $this->supported = $host->supported();
        $this->services = $this->supported ? $host->all() : [];
        $this->checkedAt = now()->format('H:i:s');

        if ($quiet) {
            return;
        }

        // Said out loud when someone pressed the button. Refreshing silently
        // looks identical to a button that does nothing when the state has not
        // changed — which is most of the time, and exactly when someone is
        // pressing it because they are unsure.
        $running = collect($this->services)->filter(fn (array $s): bool => $s['running'])->count();

        Notification::make()
            ->title('Checked just now')
            ->body(sprintf('%d of %d running.', $running, count($this->services)))
            ->success()
            ->send();
    }

    /**
     * The last few lines each service wrote.
     *
     * A service that will not start says why in its log, and without this the
     * only way to read it is a terminal — which is the thing this page exists
     * to avoid.
     */
    public function logFor(string $key): string
    {
        return app(HostServices::class)->log($key);
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

    public function toggleLog(string $key): void
    {
        if ($this->showingLog === $key) {
            $this->showingLog = null;
            $this->logContents = '';

            return;
        }

        $this->showingLog = $key;
        $this->logContents = $this->logFor($key);
    }

    public function installService(string $key): void
    {
        $host = app(HostServices::class);
        $installed = $host->install($key);

        $this->refreshServices();

        // The actual reason, not a list of things to check. "Check that the
        // plist exists and launchctl is available" names two possibilities and
        // diagnoses neither, which leaves someone guessing at a machine they
        // cannot see into.
        Notification::make()
            ->title($installed ? 'Service installed' : 'Could not install it')
            ->body($installed
                ? 'It will start on its own from now on, including after a reboot.'
                : ($host->lastError ?? 'No reason given.'))
            ->{$installed ? 'success' : 'danger'}()
            ->persistent()
            ->send();

        if (! $installed) {
            // Opened automatically: the log is where a service that will not
            // start explains itself, and asking someone to press another button
            // to find that out is a step too many.
            $this->showingLog = $key;
            $this->logContents = $host->log($key);
        }
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
                ->action(fn () => $this->refreshServices(quiet: false)),

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
