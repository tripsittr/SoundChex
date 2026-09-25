<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Filament\Pages;

use App\Filament\Concerns\RestrictsToServerAdmins;
use App\Jobs\ProbeNetworkJob;
use App\Services\NetworkAddresses;
use BackedEnum;
use Filament\Actions\Action;
use App\Models\Profile;
use Illuminate\Support\Facades\Auth;
use App\Services\Dlna\DlnaSettings;
use App\Services\SettingsService;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/**
 * How devices reach this server, and how quickly.
 *
 * A self-hosted library is usually reachable several ways at once, and they
 * are not equivalent: on one real setup the tailnet answered in 26ms, the LAN
 * in 180ms, and the public tunnel in 735ms. An app that knows only the public
 * address waits three quarters of a second for every page while sitting on the
 * same wifi as the server.
 *
 * This page exists so that is visible and fixable rather than a mystery.
 */
class Network extends Page
{
    use RestrictsToServerAdmins;


    protected string $view = 'filament.pages.network';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSignal;

    protected static string|UnitEnum|null $navigationGroup = 'System';

    protected static ?string $title = 'Network';

    protected static ?string $navigationLabel = 'Network';

    /** @var array<int, array{address: string, ms: int|null, reachable: bool}> */
    public array $results = [];

    public string $newAddress = '';

    /** Whether the library is advertised to the LAN over DLNA (S-7). */
    public bool $dlnaEnabled = false;

    /** Whose view of the library DLNA serves — a TV cannot say who is watching. */
    public ?int $dlnaProfile = null;

    public string $dlnaName = '';

    public function mount(): void
    {
        $this->results = app(NetworkAddresses::class)->probe();

        $dlna = app(DlnaSettings::class);
        $this->dlnaEnabled = $dlna->enabled();
        $this->dlnaProfile = $dlna->profile()?->id;
        $this->dlnaName = $dlna->friendlyName();
    }

    /**
     * Saves the DLNA settings.
     *
     * Switching it on without choosing a profile is refused rather than
     * defaulted: DLNA cannot ask who is browsing, so the profile *is* the
     * access control, and quietly picking one would be picking who can see
     * what.
     */
    public function saveDlna(): void
    {
        $settings = app(SettingsService::class);

        if ($this->dlnaEnabled && $this->dlnaProfile === null) {
            Notification::make()
                ->title('Choose which profile DLNA shows')
                ->body('A TV cannot say who is watching, so one profile stands for every device on the network.')
                ->danger()
                ->send();

            return;
        }

        $settings->set(DlnaSettings::ENABLED, $this->dlnaEnabled);
        $settings->set(DlnaSettings::PROFILE, $this->dlnaProfile);
        $settings->set(DlnaSettings::NAME, trim($this->dlnaName));

        Notification::make()
            ->title($this->dlnaEnabled ? 'Advertising on the local network' : 'No longer advertising')
            ->body($this->dlnaEnabled
                ? 'Devices on this network will list it within a minute or so.'
                : 'Devices will drop it from their lists shortly.')
            ->success()
            ->send();
    }

    /**
     * The profiles DLNA can be pointed at.
     *
     * This account's only. Profiles belong to accounts, and a server with more
     * than one would otherwise offer another household's profiles as the face
     * of the living-room TV.
     */
    public function profileOptions(): array
    {
        return Profile::query()
            ->where('user_id', Auth::id())
            ->orderByDesc('is_owner')
            ->orderBy('name')
            ->get()
            ->mapWithKeys(fn (Profile $p) => [
                $p->id => $p->max_rating !== null
                    ? "{$p->name} (up to {$p->max_rating})"
                    : $p->name,
            ])
            ->all();
    }

    public function retest(): void
    {
        // Queued rather than measured here. This page is served by the thing
        // being probed, and `artisan serve` is single-threaded — probing
        // inline waits on the process that would answer and times out against
        // itself, which is how the dashboard came to report a healthy server
        // as unreachable.
        ProbeNetworkJob::dispatch();

        Notification::make()
            ->title('Retesting addresses')
            ->body('Results appear here once the queue picks it up.')
            ->success()
            ->send();
    }

    public function addAddress(): void
    {
        $network = app(NetworkAddresses::class);

        if (trim($this->newAddress) === '') {
            return;
        }

        $network->save([...$network->all(), $this->newAddress]);

        $this->newAddress = '';
        ProbeNetworkJob::dispatch();
        $this->results = $network->probe();

        Notification::make()->title('Address added')->success()->send();
    }

    public function removeAddress(string $address): void
    {
        $network = app(NetworkAddresses::class);

        $network->save(array_filter(
            $network->all(),
            fn (string $candidate): bool => $candidate !== $address,
        ));

        ProbeNetworkJob::dispatch();
        $this->results = $network->probe();

        Notification::make()->title('Address removed')->success()->send();
    }

    /**
     * Puts back whatever the machine can work out about itself.
     *
     * Useful after a network change: a new router hands out a different LAN
     * address, and the stored one then points nowhere.
     */
    public function redetect(): void
    {
        $network = app(NetworkAddresses::class);

        $network->save([...$network->all(), ...$network->detected()]);

        ProbeNetworkJob::dispatch();
        $this->results = $network->probe();

        Notification::make()->title('Re-detected this machine\'s addresses')->success()->send();
    }

    /**
     * A plain-language label for an address.
     *
     * The address itself says nothing about *why* it is fast or slow, and that
     * is the actual question someone opens this page with.
     */
    public function describe(string $address): string
    {
        $host = (string) parse_url($address, PHP_URL_HOST);

        return match (true) {
            str_starts_with($host, '192.168.'),
            str_starts_with($host, '10.'),
            str_starts_with($host, '172.') => 'Local network — fastest at home, unreachable elsewhere',
            str_starts_with($host, '100.') => 'Tailscale — direct device-to-device, works anywhere the tailnet does',
            str_contains($host, 'ts.net') => 'Tailscale Funnel — public, but relayed through Tailscale servers',
            $host === 'localhost' || str_starts_with($host, '127.') => 'This machine only',
            default => 'Public address',
        };
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('retest')->label('Test again')->action('retest'),
            Action::make('redetect')->label('Re-detect')->color('gray')->action('redetect'),
        ];
    }
}
