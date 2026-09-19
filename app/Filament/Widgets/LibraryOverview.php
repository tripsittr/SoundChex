<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Filament\Widgets;

use App\Enums\MediaItemType;
use App\Enums\ProcessingStatus;
use App\Filament\Pages\Dashboard;
use App\Models\MediaItem;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Number;

/**
 * Top-line library figures: how much is catalogued, how big it is on disk,
 * how much is being played, and how much needs a human look.
 */
class LibraryOverview extends StatsOverviewWidget
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

    protected static ?int $sort = -2;

    protected ?string $pollingInterval = null;

    protected function getStats(): array
    {
        $counts = MediaItem::query()
            ->selectRaw('type, count(*) as total')
            ->groupBy('type')
            ->pluck('total', 'type')
            ->all();

        $total = array_sum($counts);

        $needsAttention = MediaItem::query()
            ->whereIn('processing_status', [
                ProcessingStatus::NeedsReview->value,
                ProcessingStatus::Failed->value,
            ])
            ->count();

        return [
            Stat::make('Items in library', Number::format($total))
                ->description($this->breakdown($counts))
                ->descriptionIcon('heroicon-m-squares-2x2')
                ->color('primary'),

            Stat::make('Storage used', $this->formattedStorage())
                ->description($this->filesOnDisk().' files on disk')
                ->descriptionIcon('heroicon-m-circle-stack')
                ->color('gray'),

            Stat::make('Needs review', Number::format($needsAttention))
                ->description($needsAttention === 0
                    ? 'Everything enriched cleanly'
                    : 'Ambiguous or failed matches')
                ->descriptionIcon($needsAttention === 0
                    ? 'heroicon-m-check-circle'
                    : 'heroicon-m-exclamation-triangle')
                ->color($needsAttention === 0 ? 'success' : 'warning'),
        ];
    }

    /**
     * @param  array<string, int>  $counts
     */
    private function breakdown(array $counts): string
    {
        if (array_sum($counts) === 0) {
            return 'Nothing catalogued yet';
        }

        return collect(MediaItemType::cases())
            ->map(fn (MediaItemType $type) => ($counts[$type->value] ?? 0).' '.$type->label())
            ->filter(fn (string $part) => ! str_starts_with($part, '0 '))
            ->implode(' · ');
    }

    private function formattedStorage(): string
    {
        return Number::fileSize($this->estimate()['bytes'], precision: 1);
    }

    private function filesOnDisk(): int
    {
        return $this->estimate()['files'];
    }

    /**
     * Library size, from a sample rather than every file.
     *
     * No file size is stored anywhere (S-119), so a real total means one
     * `filesize()` per item — 8,338 of them, on the page that loads most
     * often. This samples two hundred and scales, cached for an hour: a
     * library's size does not change between two page loads, and the previous
     * version paid the full cost on every one.
     *
     * Random rather than the first two hundred, because the earliest imports
     * here are music and the latest are films — two orders of magnitude apart,
     * so an ordered sample would be badly biased.
     *
     * @return array{bytes: int, files: int}
     */
    private function estimate(): array
    {
        return Cache::remember('library_size_estimate', now()->addHour(), function (): array {
            $files = MediaItem::query()->whereNotNull('file_path')->count();

            if ($files === 0) {
                return ['bytes' => 0, 'files' => 0];
            }

            // The stored sizes (S-119): an exact SUM, cheap. Items catalogued
            // before the column existed have null and are sampled below, so the
            // figure is exact once `library:backfill-sizes` has run and only
            // approximate for the shrinking set that has not been read yet.
            $storedBytes = (int) MediaItem::query()
                ->whereNotNull('file_path')
                ->whereNotNull('file_size')
                ->sum('file_size');

            $unsized = MediaItem::query()
                ->whereNotNull('file_path')
                ->whereNull('file_size')
                ->count();

            if ($unsized === 0) {
                // Everything is stored — an exact total, no disk reads at all.
                return ['bytes' => $storedBytes, 'files' => $files];
            }

            // Estimate only the still-unsized remainder, by sampling it and
            // scaling to how many are unsized — the same sampling the whole
            // library used to need, now bounded to what has not been backfilled.
            $seen = 0;
            $sampledBytes = 0;

            $sample = MediaItem::query()
                ->whereNotNull('file_path')
                ->whereNull('file_size')
                ->inRandomOrder()
                ->limit(200)
                ->get(['id', 'file_path', 'converted_path']);

            foreach ($sample as $item) {
                $path = $item->absoluteFilePath();

                if ($path === null || ! is_file($path)) {
                    continue;
                }

                $size = @filesize($path);

                if ($size === false) {
                    continue;
                }

                $seen++;
                $sampledBytes += $size;
            }

            $estimatedRemainder = $seen > 0 ? (int) round(($sampledBytes / $seen) * $unsized) : 0;

            return [
                'bytes' => $storedBytes + $estimatedRemainder,
                'files' => $files,
            ];
        });
    }
}
