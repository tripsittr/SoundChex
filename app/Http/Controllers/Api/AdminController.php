<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MediaItem;
use App\Models\MediaPlay;
use App\Models\Profile;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * The admin surface for the native app.
 *
 * A subset of the Filament panel — a read-only dashboard, item edit, and user /
 * profile management — for a profile that may administer. Gated by the same test
 * the web panel's door uses (owner or library-admin), applied per request in the
 * middleware alias below rather than trusting the client's `is_admin` flag.
 */
class AdminController extends Controller
{
    /**
     * Dashboard figures: what is in the library, and recent activity.
     *
     * Counts are exact and cheap (indexed group-by). Storage is deliberately
     * omitted here rather than sampled — S-119 in the server repo tracks adding a
     * real size column; a sampled figure on a dashboard reads as authoritative
     * when it is not.
     */
    public function stats(): JsonResponse
    {
        $counts = MediaItem::query()
            ->selectRaw('type, count(*) as total')
            ->groupBy('type')
            ->pluck('total', 'type');

        $recentPlays = MediaPlay::query()
            ->where('created_at', '>=', now()->subDays(7))
            ->count();

        return response()->json([
            'library' => [
                'music' => (int) ($counts['music'] ?? 0),
                'movie' => (int) ($counts['movie'] ?? 0),
                'show' => (int) ($counts['show'] ?? 0),
                'book' => (int) ($counts['book'] ?? 0),
                'total' => (int) $counts->sum(),
            ],
            'accounts' => User::count(),
            'profiles' => Profile::count(),
            'plays_last_7_days' => $recentPlays,
            'top_items' => $this->topItems(),
        ]);
    }

    /**
     * The most-played items over the last 30 days — the "what's popular" rail a
     * dashboard leads with.
     *
     * @return array<int, array<string, mixed>>
     */
    private function topItems(): array
    {
        return MediaPlay::query()
            ->select('media_item_id', DB::raw('count(*) as plays'))
            ->where('created_at', '>=', now()->subDays(30))
            ->groupBy('media_item_id')
            ->orderByDesc('plays')
            ->limit(10)
            ->with('mediaItem')
            ->get()
            ->filter(fn ($row) => $row->mediaItem !== null)
            ->map(fn ($row): array => [
                'id' => $row->mediaItem->id,
                'title' => $row->mediaItem->title,
                'subtitle' => $row->mediaItem->subtitle(),
                'artwork' => $row->mediaItem->coverUrl(),
                'plays' => (int) $row->plays,
            ])
            ->values()
            ->all();
    }
}
