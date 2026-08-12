<?php

namespace App\Filament\Widgets;

use App\Enums\MediaItemType;
use App\Enums\ProcessingStatus;
use App\Models\MediaItem;
use App\Models\MediaPlay;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Number;

/**
 * Top-line library figures: how much is catalogued, how big it is on disk,
 * how much is being played, and how much needs a human look.
 */
class LibraryOverview extends StatsOverviewWidget
{
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

        $playsThisWeek = MediaPlay::query()
            ->where('created_at', '>=', now()->subWeek())
            ->count();

        return [
            Stat::make('Items in library', Number::format($total))
                ->description($this->breakdown($counts))
                ->descriptionIcon('heroicon-m-squares-2x2')
                ->color('primary'),

            Stat::make('Storage used', $this->formattedStorage())
                ->description($this->filesOnDisk() . ' files on disk')
                ->descriptionIcon('heroicon-m-circle-stack')
                ->color('gray'),

            Stat::make('Plays this week', Number::format($playsThisWeek))
                ->description($this->playsDescription())
                ->descriptionIcon('heroicon-m-play')
                ->color('success'),

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
     * @param array<string, int> $counts
     */
    private function breakdown(array $counts): string
    {
        if (array_sum($counts) === 0) {
            return 'Nothing catalogued yet';
        }

        return collect(MediaItemType::cases())
            ->map(fn (MediaItemType $type) => ($counts[$type->value] ?? 0) . ' ' . $type->label())
            ->filter(fn (string $part) => ! str_starts_with($part, '0 '))
            ->implode(' · ');
    }

    /**
     * Total bytes of every catalogued file.
     *
     * Read from disk rather than stored, since files imported in place aren't
     * copied and their size isn't recorded anywhere.
     */
    private function formattedStorage(): string
    {
        $bytes = 0;

        foreach ($this->readableFilePaths() as $path) {
            $bytes += filesize($path) ?: 0;
        }

        return Number::fileSize($bytes, precision: 1);
    }

    private function filesOnDisk(): int
    {
        return count($this->readableFilePaths());
    }

    /**
     * @return array<int, string>
     */
    private function readableFilePaths(): array
    {
        // Cached per request: both stats above walk the same list.
        static $paths = null;

        if ($paths !== null) {
            return $paths;
        }

        $paths = MediaItem::query()
            ->whereNotNull('file_path')
            ->get()
            ->map(fn (MediaItem $item) => $item->absoluteFilePath())
            ->filter()
            ->all();

        return $paths;
    }

    private function playsDescription(): string
    {
        $allTime = MediaPlay::count();

        if ($allTime === 0) {
            return 'No plays recorded yet';
        }

        return Number::format($allTime) . ' all time';
    }
}
