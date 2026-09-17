<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Filament\Pages;

use App\Filament\Concerns\RestrictsToServerAdmins;
use App\Models\DeviceReport;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/**
 * What devices have reported going wrong.
 *
 * A phone has no console anyone can reach, so a failed navigation is a white
 * flash and nothing else — and reading a diagnostic panel aloud is a poor way
 * to debug. Reports arrive on their own and are read here.
 */
class DeviceReports extends Page
{
    use RestrictsToServerAdmins;


    protected string $view = 'filament.pages.device-reports';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedExclamationTriangle;

    protected static string|UnitEnum|null $navigationGroup = 'System';

    protected static ?string $title = 'Device reports';

    protected static ?string $navigationLabel = 'Device reports';

    /** @var array<int, array<string, mixed>> */
    public array $reports = [];

    public string $deviceFilter = '';

    public string $kindFilter = '';

    public string $logFilter = '';

    public bool $failuresOnly = false;

    /** Re-runs the query whenever a filter moves. */
    public function updated(): void
    {
        $this->loadReports();
    }

    public function mount(): void
    {
        $this->loadReports();
    }

    public function loadReports(): void
    {
        $this->reports = DeviceReport::query()
            ->when($this->deviceFilter !== '', fn ($q) => $q->where('device', $this->deviceFilter))
            ->when($this->kindFilter !== '', fn ($q) => $q->where('kind', $this->kindFilter))
            // One report holds many events, so this asks whether it contains
            // the kind rather than whether it is one.
            ->when($this->logFilter !== '', fn ($q) => $q->where('events', 'like', '%' . $this->logFilter . '%'))
            ->when($this->failuresOnly, fn ($q) => $q->where(fn ($inner) => $inner
                ->where('events', 'like', '%:failed%')
                ->orWhere('events', 'like', '%"error"%')
                ->orWhere('events', 'like', '%"rejection"%')))
            ->latest('id')
            ->limit(60)
            ->get()
            ->map(fn (DeviceReport $report): array => [
                'id' => $report->id,
                // The name if it has one, and the id otherwise: a device that
                // has never been named still needs telling from the others.
                'name' => $report->name ?: 'Unnamed · ' . substr($report->device, 0, 8),
                'device' => substr($report->device, 0, 8),
                'kind' => $report->kind ?: 'unknown',
                'ip' => $report->ip,
                'platform' => $this->shortPlatform($report->platform),
                'build' => $report->build,
                // The shell cannot update itself, so a device can be current
                // on the served build and months behind on this one.
                'shell' => $report->shell ? substr($report->shell, 0, 8) : null,
                'app_version' => $report->app_version,
                'origin' => $report->origin,
                'at' => $report->created_at?->format('D j M, H:i:s'),
                'when' => $report->created_at?->diffForHumans(),
                'events' => $report->events ?? [],
            ])
            ->all();
    }

    /** Devices that have ever reported, for the filter. */
    public function devices(): array
    {
        return DeviceReport::query()
            ->selectRaw('device, COALESCE(NULLIF(name, \'\'), device) as label')
            ->distinct()
            ->pluck('label', 'device')
            ->all();
    }

    /**
     * A user agent, cut to the part that says which device it is.
     *
     * The full string is a paragraph of version numbers, and the only question
     * being asked here is "which of my devices was this".
     */
    private function shortPlatform(?string $platform): string
    {
        if (blank($platform)) {
            return 'unknown';
        }

        return match (true) {
            str_contains($platform, 'iPhone') => 'iPhone',
            str_contains($platform, 'iPad') => 'iPad',
            str_contains($platform, 'Android') => 'Android',
            str_contains($platform, 'Macintosh') => 'Mac',
            str_contains($platform, 'Windows') => 'Windows',
            default => substr($platform, 0, 20),
        };
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
                ->action(function (): void {
                    $this->loadReports();

                    Notification::make()
                        ->title(sprintf('%d report%s', count($this->reports), count($this->reports) === 1 ? '' : 's'))
                        ->success()
                        ->send();
                }),

            Action::make('clear')
                ->label('Clear all')
                ->icon(Heroicon::OutlinedTrash)
                ->color('danger')
                ->visible(fn (): bool => $this->reports !== [])
                ->requiresConfirmation()
                ->action(function (): void {
                    DeviceReport::truncate();
                    $this->loadReports();

                    Notification::make()->title('Cleared')->success()->send();
                }),
        ];
    }
}
