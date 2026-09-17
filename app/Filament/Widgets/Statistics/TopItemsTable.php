<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Filament\Widgets\Statistics;

use App\Enums\MediaItemType;
use App\Filament\Pages\MusicStatisticsPage;
use App\Models\MediaItem;
use App\Services\LibraryStatistics;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Support\Carbon;

/**
 * The most-played items, with their artwork.
 *
 * A real Filament table rather than hand-rolled markup: the dashboard's
 * "Recently added" uses one and looks considerably better for it — artwork,
 * badges, and chrome that follows the panel instead of drifting from it every
 * time Filament changes.
 *
 * Built from the play counts rather than by querying `MediaItem` and sorting:
 * plays live on another table and the window and profile filters apply to
 * them, so the statistics service decides which rows and this decides how they
 * look.
 */
class TopItemsTable extends TableWidget
{
    /** Passed down from the page, so the table shares its filters. */
    public string $range = '30';

    public string $profileId = '';

    public string $type = 'music';

    protected int|string|array $columnSpan = 'full';

    /**
     * Repeated rather than inherited from the page: Livewire resolves a
     * component by name, so this is reachable without it.
     */
    public static function canView(): bool
    {
        return MusicStatisticsPage::canAccess();
    }

    /** Enough to be useful on screen; the page's own lists hold all hundred. */
    private const ROWS = 25;

    protected function mediaType(): MediaItemType
    {
        return MediaItemType::tryFrom($this->type) ?? MediaItemType::Music;
    }

    public function table(Table $table): Table
    {
        $stats = app(LibraryStatistics::class)->for($this->mediaType());
        $labels = $stats->labels();

        $from = $this->range === 'all'
            ? null
            : Carbon::today()->subDays((int) $this->range);

        $rows = $stats->topItems(
            $from,
            null,
            $this->profileId === '' ? null : (int) $this->profileId,
            self::ROWS,
        );

        // Play counts keyed by id, so the columns below can read them off a
        // MediaItem without a second query per row.
        $plays = $rows->pluck('plays', 'id');
        $seconds = $rows->pluck('seconds', 'id');

        return $table
            ->heading('Top ' . \Illuminate\Support\Str::plural(strtolower($labels['item'])))
            ->description($this->windowLabel())
            ->query(
                // Ordered by the play ranking rather than by anything on the
                // row: `whereIn` returns them in id order otherwise, which is
                // arbitrary and makes the table disagree with its own heading.
                MediaItem::query()
                    ->with(['musicMetadata', 'movieMetadata', 'showMetadata', 'bookMetadata'])
                    ->whereIn('id', $rows->pluck('id')->all()),
            )
            ->defaultSort(fn ($query) => $query)
            ->modifyQueryUsing(function ($query) use ($rows) {
                // Cast before interpolating. These ids come from the database
                // rather than a request, so this is not exploitable today —
                // but an interpolated list in a raw fragment is a shape that
                // becomes an injection the moment its source changes, and the
                // cast costs nothing.
                $ids = $rows->pluck('id')
                    ->map(fn ($id): int => (int) $id)
                    ->filter()
                    ->implode(',');

                return $ids === ''
                    ? $query
                    : $query->orderByRaw("instr(',{$ids},', ',' || media_items.id || ',')");
            })
            ->paginated(false)
            ->columns([
                ImageColumn::make('cover_image_url')
                    ->label('')
                    ->square()
                    // Through the model rather than the raw column: the stored
                    // value is a path relative to the storage disk, and path
                    // segments carry spaces and commas from artist and album
                    // names. `coverUrl()` is the only thing that encodes them.
                    ->getStateUsing(fn (MediaItem $record): ?string => $record->coverUrl())
                    ->defaultImageUrl(fn (): string => 'https://placehold.co/72x72/1f2937/6b7280?text=%E2%99%AB'),

                TextColumn::make('title')
                    ->label($labels['item'])
                    ->limit(46)
                    ->weight('medium')
                    ->description(fn (MediaItem $record): ?string => $record->subtitle()),

                TextColumn::make('plays')
                    ->label('Plays')
                    ->badge()
                    ->color('primary')
                    ->alignCenter()
                    ->state(fn (MediaItem $record): string => number_format((int) ($plays[$record->id] ?? 0))),

                TextColumn::make('listened')
                    ->label('Listened')
                    ->alignEnd()
                    ->color('gray')
                    ->state(function (MediaItem $record) use ($seconds): string {
                        $value = (int) ($seconds[$record->id] ?? 0);

                        if ($value <= 0) return '—';
                        if ($value < 3600) return floor($value / 60) . 'm';

                        return number_format($value / 3600, 1) . 'h';
                    }),
            ])
            ->emptyStateHeading('Nothing played in this period')
            ->emptyStateDescription('Plays recorded here will appear once something is listened to.');
    }

    /** The window, so the table says what it covers rather than implying it. */
    private function windowLabel(): string
    {
        $period = match ($this->range) {
            'all' => 'All time',
            default => 'Last ' . $this->range . ' days',
        };

        $who = $this->profileId === ''
            ? 'everyone'
            : (\App\Models\Profile::find((int) $this->profileId)?->name ?? 'everyone');

        return $period . ' · ' . $who . ' · by plays';
    }
}
