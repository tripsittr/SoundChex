<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Filament\Widgets;

use App\Enums\MediaItemType;
use App\Filament\Pages\Dashboard;
use App\Models\MediaItem;
use Filament\Support\RawJs;
use Filament\Widgets\ChartWidget;
use Illuminate\Support\Collection;

/**
 * Genre distribution, filterable per media type.
 *
 * A horizontal bar rather than a pie: genre names are long, and a pie with a
 * dozen slices needs a legend nobody reads.
 */
class GenreSplit extends ChartWidget
{
    /**
     * Widgets are renderable independently of the page that hosts them, so
     * this repeats the dashboard's gate rather than relying on it. Without it
     * a capped profile saw library counts, storage totals and titles above
     * its rating.
     */
    public static function canView(): bool
    {
        return Dashboard::canAccess();
    }

    protected ?string $heading = 'Genre split';

    protected static ?int $sort = 0;

    protected int|string|array $columnSpan = 'full';

    protected ?string $maxHeight = '320px';

    /** Long tails aren't readable; everything past this is grouped. */
    private const MAX_SLICES = 8;

    public ?string $filter = 'all';

    protected function getFilters(): ?array
    {
        $available = MediaItem::unresolved()
            ->distinct()
            ->pluck('type')
            ->map(fn ($type) => $type instanceof MediaItemType ? $type->value : (string) $type)
            ->all();

        $filters = ['all' => 'All types'];

        foreach (MediaItemType::cases() as $type) {
            if (in_array($type->value, $available, true)) {
                $filters[$type->value] = str($type->label())->plural()->toString();
            }
        }

        return $filters;
    }

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getData(): array
    {
        $rows = $this->genreCounts();

        if ($rows->isEmpty()) {
            return ['datasets' => [], 'labels' => []];
        }

        $top = $rows->take(self::MAX_SLICES);
        $remainder = $rows->skip(self::MAX_SLICES)->sum('total');

        $labels = $top->pluck('genre')->all();
        $values = $top->pluck('total')->all();

        if ($remainder > 0) {
            $labels[] = 'Other';
            $values[] = $remainder;
        }

        return [
            'datasets' => [[
                'label' => 'Items',
                'data' => $values,
                'backgroundColor' => '#e11d3a',
                'borderRadius' => 4,
            ]],
            'labels' => $labels,
        ];
    }

    /**
     * @return Collection<int, object>
     */
    private function genreCounts()
    {
        return MediaItem::unresolved()
            ->join('media_tags', 'media_tags.media_item_id', '=', 'media_items.id')
            ->where('media_tags.type', 'genre')
            ->when(
                $this->filter !== 'all',
                fn ($query) => $query->where('media_items.type', $this->filter),
            )
            ->select('media_tags.value as genre')
            ->selectRaw('count(*) as total')
            ->groupBy('media_tags.value')
            ->orderByDesc('total')
            ->get();
    }

    protected function getOptions(): RawJs
    {
        // Horizontal bars, no legend: one dataset makes a legend pure noise.
        return RawJs::make(<<<'JS'
            {
                indexAxis: 'y',
                plugins: { legend: { display: false } },
                scales: {
                    x: { beginAtZero: true, ticks: { precision: 0 } },
                    y: { grid: { display: false } },
                },
            }
        JS);
    }
}
