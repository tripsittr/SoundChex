<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Filament\Widgets\Statistics;

use Filament\Support\RawJs;

/**
 * The most-played by whatever this type is organised around.
 *
 * Artist for music, director for a film, creator for a show, author for a
 * book — the heading follows the open tab rather than claiming "artists" over
 * a list of directors.
 */
class TopArtistsChart extends StatisticsChart
{
    public function getHeading(): string | \Illuminate\Contracts\Support\Htmlable | null
    {
        return 'Top ' . strtolower(\Illuminate\Support\Str::plural($this->labels()['primary']));
    }

    protected function measures(): string
    {
        return 'plays per ' . strtolower($this->labels()['primary']) . ', top ' . self::BARS;
    }

    /** Ten bars fit the height; the table beside it holds all hundred. */
    private const BARS = 10;

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getData(): array
    {
        $rows = $this->stats()
            ->topPrimary($this->from(), null, $this->profile(), self::BARS);

        if ($rows->isEmpty()) {
            return $this->empty();
        }

        return [
            'datasets' => [[
                'label' => 'Plays',
                'data' => $rows->pluck('plays')->map(fn ($v) => (int) $v)->all(),
                'backgroundColor' => '#e11d3a',
                'borderRadius' => 4,
                // Carried for the tooltip: a play count alone cannot tell
                // twelve skips from twelve full listens, and the difference is
                // the whole reason listening time is recorded.
                'tracks' => $rows->pluck('items')->map(fn ($v) => (int) $v)->all(),
                'minutes' => $rows->pluck('seconds')->map(fn ($v) => (int) round($v / 60))->all(),
            ]],
            'labels' => $rows->pluck('grouping')->all(),
        ];
    }

    protected function getOptions(): RawJs
    {
        // Horizontal: artist names are long, and rotated labels are unreadable.
        // No legend — one dataset makes it noise.
        return RawJs::make(<<<'JS'
            {
                indexAxis: 'y',
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            label: (ctx) => {
                                const d = ctx.dataset;
                                const i = ctx.dataIndex;
                                const parts = [ctx.parsed.x + ' plays'];

                                if (d.tracks?.[i]) parts.push(d.tracks[i] + ' tracks');
                                if (d.minutes?.[i]) parts.push(d.minutes[i] + ' min listened');

                                return parts.join(' · ');
                            },
                        },
                    },
                },
                scales: {
                    x: { beginAtZero: true, ticks: { precision: 0 }, title: { display: true, text: 'Plays' } },
                    y: { grid: { display: false } },
                },
            }
        JS);
    }
}
