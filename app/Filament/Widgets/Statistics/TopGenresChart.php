<?php

namespace App\Filament\Widgets\Statistics;

use Filament\Support\RawJs;

/**
 * The genres played most in the window.
 *
 * A doughnut rather than bars: genres are few and the interesting question is
 * proportion — how much of the listening one genre accounts for — which a
 * share reads better than a length.
 */
class TopGenresChart extends StatisticsChart
{
    public function getHeading(): string | \Illuminate\Contracts\Support\Htmlable | null
    {
        return 'Top genres';
    }

    protected function measures(): string
    {
        // Said here because the shares sum past 100%: a track carries several
        // genres, so each of its plays is counted under all of them.
        return 'share of plays — a track counts under every genre it carries';
    }

    /** Past this the slices are unreadable and the rest is grouped. */
    private const SLICES = 8;

    protected function getType(): string
    {
        return 'doughnut';
    }

    protected function getData(): array
    {
        $rows = $this->stats()->topGenres($this->from(), null, $this->profile());

        if ($rows->isEmpty()) {
            return $this->empty();
        }

        $top = $rows->take(self::SLICES);
        $rest = (int) $rows->skip(self::SLICES)->sum('plays');

        $labels = $top->pluck('genre')->all();
        $values = $top->pluck('plays')->map(fn ($v) => (int) $v)->all();

        if ($rest > 0) {
            $labels[] = 'Other';
            $values[] = $rest;
        }

        return [
            'datasets' => [[
                'label' => 'Plays',
                'data' => $values,
                // Named rather than generated, so the same genre keeps the
                // same colour between loads.
                'backgroundColor' => [
                    '#e11d3a', '#f97316', '#eab308', '#22c55e',
                    '#06b6d4', '#6366f1', '#a855f7', '#ec4899', '#64748b',
                ],
                'borderWidth' => 0,
            ]],
            'labels' => $labels,
        ];
    }

    protected function getOptions(): RawJs
    {
        return RawJs::make(<<<'JS'
            {
                plugins: {
                    legend: { position: 'right', labels: { boxWidth: 12, font: { size: 11 } } },
                    tooltip: {
                        callbacks: {
                            label: (ctx) => {
                                const total = ctx.dataset.data.reduce((a, b) => a + b, 0);
                                const pct = total ? Math.round((ctx.parsed / total) * 100) : 0;

                                return ctx.label + ': ' + ctx.parsed + ' plays (' + pct + '%)';
                            },
                        },
                    },
                },
                cutout: '55%',
            }
        JS);
    }
}
