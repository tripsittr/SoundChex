<?php

namespace App\Filament\Pages;

use App\Filament\Concerns\RestrictsToAdmins;
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
    use RestrictsToAdmins;

    protected static function requiredPermission(): string
    {
        return 'View:DeviceReports';
    }

    protected string $view = 'filament.pages.device-reports';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedExclamationTriangle;

    protected static string|UnitEnum|null $navigationGroup = 'System';

    protected static ?string $title = 'Device reports';

    protected static ?string $navigationLabel = 'Device reports';

    /** @var array<int, array<string, mixed>> */
    public array $reports = [];

    public function mount(): void
    {
        $this->loadReports();
    }

    public function loadReports(): void
    {
        $this->reports = DeviceReport::query()
            ->latest('id')
            ->limit(30)
            ->get()
            ->map(fn (DeviceReport $report): array => [
                'id' => $report->id,
                'device' => substr($report->device, 0, 8),
                'platform' => $this->shortPlatform($report->platform),
                'build' => $report->build,
                'origin' => $report->origin,
                'when' => $report->created_at?->diffForHumans(),
                'events' => $report->events ?? [],
            ])
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
