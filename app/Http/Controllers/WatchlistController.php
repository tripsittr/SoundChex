<?php

namespace App\Http\Controllers;

use App\Models\MediaItem;
use App\Services\ContentGate;
use App\Services\CurrentProfile;
use Illuminate\Http\JsonResponse;

/**
 * A profile's list of things to get to.
 *
 * Distinct from the `wishlist` flag on a media item, which marks something the
 * household doesn't own yet. This is per person and about intent to watch.
 */
class WatchlistController extends Controller
{
    public function toggle(MediaItem $item, CurrentProfile $profiles, ContentGate $gate): JsonResponse
    {
        $profile = $profiles->get();

        abort_unless($profile !== null, 403);

        // A capped title shouldn't be addable by a restricted profile, or the
        // list becomes a way around the filter.
        abort_unless($gate->allows($item), 404);

        $existing = $profile->watchlist()->where('media_item_id', $item->id)->exists();

        if ($existing) {
            $profile->watchlist()->detach($item->id);
        } else {
            $profile->watchlist()->attach($item->id);
        }

        return response()->json([
            'inWatchlist' => ! $existing,
            'count' => $profile->watchlist()->count(),
        ]);
    }
}
