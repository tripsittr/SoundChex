<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Filament\Widgets\Statistics;

use Filament\Support\RawJs;

/**
 * When listening happens, by hour of day.
 *
 * Every hour is drawn including the empty ones: 3am at zero says nobody
 * listens then, where a missing bar would just draw a narrower day.
 */
class ListeningClockChart extends StatisticsChart
{
    public function getHeading(): string | \Illuminate\Contracts\Support\Htmlable | null
    {
        return 'Listening by hour';
    }

    protected function measures(): string
    {
        return 'plays started, by hour of day';
    }

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getData(): array
    {
        $hours = $this->stats()->clock($this->from(), null, $this->profile())['hours'];

        return [
            'datasets' => [[
                'label' => 'Plays',
                'data' => array_values($hours),
                'backgroundColor' => '#e11d3a',
                'borderRadius' => 3,
            ]],
            'labels' => array_map(
                fn (int $hour) => sprintf('%02d', $hour),
                array_keys($hours),
            ),
        ];
    }

    protected function getOptions(): RawJs
    {
        return RawJs::make(<<<'JS'
            {
                plugins: { legend: { display: false } },
                scales: {
                    x: { grid: { display: false }, ticks: { maxRotation: 0, autoSkipPadding: 8 },
                         title: { display: true, text: 'Hour of day' } },
                    y: { beginAtZero: true, ticks: { precision: 0 }, title: { display: true, text: 'Plays' } },
                },
            }
        JS);
    }
}
