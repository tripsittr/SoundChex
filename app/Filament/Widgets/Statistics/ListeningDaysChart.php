<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Filament\Widgets\Statistics;

use Filament\Support\RawJs;

/** When listening happens, by day of week. */
class ListeningDaysChart extends StatisticsChart
{
    public function getHeading(): string | \Illuminate\Contracts\Support\Htmlable | null
    {
        return 'Listening by day';
    }

    protected function measures(): string
    {
        return 'plays started, by day of week';
    }

    private const DAYS = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getData(): array
    {
        $days = $this->stats()->clock($this->from(), null, $this->profile())['days'];

        return [
            'datasets' => [[
                'label' => 'Plays',
                'data' => array_values($days),
                'backgroundColor' => '#6366f1',
                'borderRadius' => 4,
            ]],
            // Keyed 0–6 from strftime('%w'), which starts on Sunday.
            'labels' => self::DAYS,
        ];
    }

    protected function getOptions(): RawJs
    {
        return RawJs::make(<<<'JS'
            {
                plugins: { legend: { display: false } },
                scales: {
                    x: { grid: { display: false }, title: { display: true, text: 'Day of week' } },
                    y: { beginAtZero: true, ticks: { precision: 0 }, title: { display: true, text: 'Plays' } },
                },
            }
        JS);
    }
}
