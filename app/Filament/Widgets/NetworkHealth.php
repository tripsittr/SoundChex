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

        // Read rather than measured. Probing here blocks: `artisan serve` is
        // single-threaded, so a request to this server made while serving a
        // request waits on the process that would answer it. This widget
        // reported "0 of 3, clients cannot reach this server" about a server
        // that answered all three — `network:probe` measures on the scheduler.
        $results = $network->probe();

        // Never measured is not the same as measured and failing, and saying
        // the second when the first is true is how a dashboard trains people
        // to ignore it.
        if (! $network->probed()) {
            return [
                Stat::make('Fastest route', 'Not measured')
                    ->description('Waiting for the scheduler')
                    ->color('gray'),

                Stat::make('Addresses answering', count($network->all()) . ' known')
                    ->description('Run network:probe to measure now')
                    ->color('gray'),
            ];
        }

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
