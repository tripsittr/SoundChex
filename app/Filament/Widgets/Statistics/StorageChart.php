<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Filament\Widgets\Statistics;

use Filament\Support\RawJs;

/**
 * What the library costs on disk, by artist.
 *
 * Estimated rather than measured: no file size is stored anywhere (S-119), so
 * this is a sampled average multiplied by the track count. The heading says
 * so, because a chart that looks exact and is not is worse than one labelled.
 */
class StorageChart extends StatisticsChart
{
    public function getHeading(): string | \Illuminate\Contracts\Support\Htmlable | null
    {
        return 'Storage by ' . strtolower($this->labels()['primary']);
    }

    protected function measures(): string
    {
        return 'estimated gigabytes on disk, whole library';
    }

    private const BARS = 10;

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getData(): array
    {
        $rows = $this->stats()->storage(self::BARS)['artists'];

        if ($rows->isEmpty()) {
            return $this->empty();
        }

        return [
            'datasets' => [[
                'label' => 'GB',
                'data' => $rows->map(
                    fn (object $row) => round($row->bytes / 1073741824, 2),
                )->all(),
                'backgroundColor' => '#22c55e',
                'borderRadius' => 4,
            ]],
            'labels' => $rows->pluck('artist')->all(),
        ];
    }

    protected function getOptions(): RawJs
    {
        return RawJs::make(<<<'JS'
            {
                indexAxis: 'y',
                plugins: {
                    legend: { display: false },
                    tooltip: { callbacks: {} },
                },
                scales: {
                    x: { beginAtZero: true, title: { display: true, text: 'GB (estimated)' } },
                    y: { grid: { display: false } },
                },
            }
        JS);
    }
}
