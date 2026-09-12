<?php

namespace App\Filament\Pages;

use App\Enums\MediaItemType;
use App\Filament\Concerns\RestrictsToAdmins;
use App\Services\LibraryStatistics;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Carbon;
use UnitEnum;

/**
 * What the library holds, and what has actually been listened to or watched.
 *
 * A custom Blade view rather than Filament widgets: the page moves every
 * figure on it through one media type, one date range and one profile filter.
 * Doing that with page widgets means each re-deriving the same window, and
 * them disagreeing the moment one is missed.
 *
 * Every number is queried live. A stored counter drifts the moment anything
 * writes a play row without going through it, and three things already do —
 * at this size the whole page is well under a second.
 */
class MusicStatisticsPage extends Page
{
    use RestrictsToAdmins;

    /**
     * The narrow key, for a member trusted with statistics and nothing else.
     *
     * Library admins reach it through the floor; this is the alternative for a
     * profile granted `View:MusicStatisticsPage` without the whole panel. The
     * name is the `View:` form, which is what is grantable — the default
     * derivation would ask for `Access:MusicStatisticsPage`, which is not.
     */
    protected static function requiredPermission(): ?string
    {
        return 'View:MusicStatisticsPage';
    }

    protected string $view = 'filament.pages.music-statistics';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBar;

    protected static string|UnitEnum|null $navigationGroup = 'Media Library';

    protected static ?string $title = 'Statistics';

    protected static ?string $navigationLabel = 'Statistics';

    /** After the media resources it describes, before anything else. */
    protected static ?int $navigationSort = 90;

    /** Windows offered, and how many days back each one reaches. */
    public const RANGES = [
        '7' => 'Last 7 days',
        '30' => 'Last 30 days',
        '90' => 'Last 90 days',
        '365' => 'Last year',
        'all' => 'All time',
    ];

    /** Which tab is open. Music, because it is 8,319 of 8,338 items here. */
    public string $type = 'music';

    public string $range = '30';

    /** Empty means every profile — the server as a whole. */
    public string $profile = '';

    /** @var array<string, mixed> */
    public array $data = [];

    public function mount(): void
    {
        $this->load();
    }

    public function updatedType(): void
    {
        $this->load();
    }

    public function updatedRange(): void
    {
        $this->load();
    }

    public function updatedProfile(): void
    {
        $this->load();
    }

    /**
     * The tabs, with how much each holds.
     *
     * Counts shown so an empty tab is obviously empty rather than looking
     * broken — this library is 8,319 music against 5 films and no shows.
     *
     * @return array<string, array{label: string, count: int}>
     */
    public function tabs(): array
    {
        $counts = \App\Models\MediaItem::query()
            ->selectRaw('type, count(*) as total')
            ->groupBy('type')
            ->pluck('total', 'type')
            ->all();

        $tabs = [];

        foreach (MediaItemType::cases() as $case) {
            $tabs[$case->value] = [
                'label' => str($case->label())->plural()->toString(),
                'count' => (int) ($counts[$case->value] ?? 0),
            ];
        }

        return $tabs;
    }

    /**
     * Everything the view draws, in one pass.
     *
     * Gathered here rather than per-section so the type, window and profile are
     * applied once — two sections disagreeing about what "last 30 days" covers
     * is how a statistics page stops being believed.
     */
    public function load(): void
    {
        $type = MediaItemType::tryFrom($this->type) ?? MediaItemType::Music;

        $stats = app(LibraryStatistics::class)->for($type);

        $from = $this->range === 'all'
            ? null
            : Carbon::today()->subDays((int) $this->range);

        $profileId = $this->profile === '' ? null : (int) $this->profile;

        $this->data = [
            'labels' => $stats->labels(),
            'library' => $stats->library(),
            'listening' => $stats->listening($from, null, $profileId),
            'items' => $stats->topItems($from, null, $profileId)->all(),
            'primary' => $stats->topPrimary($from, null, $profileId)->all(),
            'genres' => $stats->topGenres($from, null, $profileId)->all(),
            'playlists' => $stats->topPlaylists($from, null, $profileId)->all(),
            'reach' => $stats->reach($profileId),
            'streaks' => $stats->streaks($profileId),
            'storage' => $stats->storage(),
            'profiles' => $stats->profiles()->all(),
            'unattributed' => $stats->unattributedPlays(),
            'from' => $from?->toDateString(),
        ];
    }
}
