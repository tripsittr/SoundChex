<?php

namespace App\Filament\Widgets;

use App\Services\NetworkAddresses;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * How fast this server is to reach, at a glance.
 *
 * Deliberately just the headline: which route is quickest and whether the
 * others answer. The full list, timings and editing live on the Network page —
 * a dashboard should say whether something needs attention, not be the place
 * attention is paid.
 */
class NetworkHealth extends StatsOverviewWidget
{
    protected static ?int $sort = 3;

    /**
     * Widgets render independently of the page hosting them, so this repeats
     * the gate rather than relying on it.
     */
    public static function canView(): bool
    {
        return \App\Filament\Pages\Dashboard::canAccess();
    }

    protected function getStats(): array
    {
        $network = app(NetworkAddresses::class);
        $results = $network->probe();
        $fastest = collect($results)->firstWhere('reachable', true);
        $reachable = collect($results)->where('reachable', true)->count();

        return [
            Stat::make('Fastest route', $fastest ? $fastest['ms'] . ' ms' : 'None')
                ->description($fastest
                    ? parse_url($fastest['address'], PHP_URL_HOST)
                    : 'No address answered')
                ->color($this->colourFor($fastest['ms'] ?? null)),

            Stat::make('Addresses answering', $reachable . ' of ' . count($results))
                ->description($reachable === 0
                    ? 'Clients cannot reach this server'
                    : 'Apps use whichever replies first')
                ->color($reachable === 0 ? 'danger' : 'success'),
        ];
    }

    /**
     * Under 100ms feels instant; past half a second every page has a visible
     * pause, which is what a relayed connection costs.
     */
    private function colourFor(?int $ms): string
    {
        return match (true) {
            $ms === null => 'danger',
            $ms < 100 => 'success',
            $ms < 500 => 'warning',
            default => 'danger',
        };
    }
}
