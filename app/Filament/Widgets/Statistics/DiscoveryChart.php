<?php

namespace App\Filament\Widgets\Statistics;

use Filament\Support\RawJs;

/**
 * First-time plays against re-listens, by day.
 *
 * Stacked, because the pair is a breakdown of one day's listening rather than
 * two independent series — the height is the day's plays, and the split is
 * how much of it was new.
 */
class DiscoveryChart extends StatisticsChart
{
    public function getHeading(): string | \Illuminate\Contracts\Support\Htmlable | null
    {
        return 'New vs repeat';
    }

    protected function measures(): string
    {
        return 'plays per day, split by whether the track had been heard before';
    }

    /** A month of bars is readable; a year of them is a smear. */
    private const DAYS = 30;

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getData(): array
    {
        $rows = $this->stats()
            ->discovery($this->from(), null, $this->profile())
            ->slice(-self::DAYS)
            ->values();

        if ($rows->isEmpty()) {
            return $this->empty();
        }

        return [
            'datasets' => [
                [
                    'label' => 'New',
                    'data' => $rows->pluck('discovered')->map(fn ($v) => (int) $v)->all(),
                    'backgroundColor' => '#e11d3a',
                    'borderRadius' => 3,
                ],
                [
                    'label' => 'Repeat',
                    'data' => $rows->pluck('repeated')->map(fn ($v) => (int) $v)->all(),
                    'backgroundColor' => '#64748b',
                    'borderRadius' => 3,
                ],
            ],
            // Day and month only: the year is already in the page's own range.
            'labels' => $rows->pluck('day')->map(
                fn ($day) => \Illuminate\Support\Carbon::parse($day)->format('j M'),
            )->all(),
        ];
    }

    protected function getOptions(): RawJs
    {
        return RawJs::make(<<<'JS'
            {
                plugins: { legend: { position: 'bottom', labels: { boxWidth: 12 } } },
                scales: {
                    x: { stacked: true, grid: { display: false }, ticks: { maxRotation: 0, autoSkipPadding: 12 } },
                    y: { stacked: true, beginAtZero: true, ticks: { precision: 0 },
                         title: { display: true, text: 'Plays' } },
                },
            }
        JS);
    }
}
