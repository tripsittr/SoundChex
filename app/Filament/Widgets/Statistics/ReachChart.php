<?php

namespace App\Filament\Widgets\Statistics;

use Filament\Support\RawJs;

/**
 * How much of the library has ever been played.
 *
 * Played against never-played as a stacked pair, because the interesting
 * number is the *unplayed* remainder — "283 artists" means little without the
 * 2,321 it is drawn from.
 *
 * All time regardless of the page's window: whether a track has ever been
 * played is not a question about the last 30 days.
 */
class ReachChart extends StatisticsChart
{
    public function getHeading(): string | \Illuminate\Contracts\Support\Htmlable | null
    {
        return 'Library vs listened';
    }

    protected function measures(): string
    {
        // All time deliberately: whether something has *ever* been played is
        // not a question about the last 30 days.
        return 'how much of the library has ever been played — all time';
    }

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getData(): array
    {
        $reach = $this->stats()->reach($this->profile());

        $played = [];
        $rest = [];

        foreach (['items', 'primary', 'genres'] as $what) {
            $played[] = $reach[$what]['played'];
            $rest[] = max(0, $reach[$what]['total'] - $reach[$what]['played']);
        }

        return [
            'datasets' => [
                [
                    'label' => 'Played',
                    'data' => $played,
                    'backgroundColor' => '#e11d3a',
                    'borderRadius' => 3,
                ],
                [
                    'label' => 'Never played',
                    'data' => $rest,
                    'backgroundColor' => '#334155',
                    'borderRadius' => 3,
                ],
            ],
            'labels' => [
                \Illuminate\Support\Str::plural($this->labels()['item']),
                \Illuminate\Support\Str::plural($this->labels()['primary']),
                'Genres',
            ],
        ];
    }

    protected function getOptions(): RawJs
    {
        return RawJs::make(<<<'JS'
            {
                indexAxis: 'y',
                plugins: { legend: { position: 'bottom', labels: { boxWidth: 12 } } },
                scales: {
                    x: { stacked: true, beginAtZero: true, ticks: { precision: 0 },
                         title: { display: true, text: 'Count' } },
                    y: { stacked: true, grid: { display: false } },
                },
            }
        JS);
    }
}
