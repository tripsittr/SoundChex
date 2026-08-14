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
    public function toggle(
        \Illuminate\Http\Request $request,
        MediaItem $item,
        CurrentProfile $profiles,
        ContentGate $gate,
    ): JsonResponse {
        $profile = $profiles->get();

        abort_unless($profile !== null, 403);

        // A capped title shouldn't be addable by a restricted profile, or the
        // list becomes a way around the filter.
        abort_unless($gate->allows($item), 404);

        $existing = $profile->watchlist()->where('media_item_id', $item->id)->exists();

        // A toggle is not replayable: sending it twice returns to where it
        // started. An offline write is replayed on reconnect and may be
        // retried after a timeout, so the queue states the result it wants
        // and this applies that instead of flipping.
        //
        // Without a stated intent it still toggles, which is what the button
        // does when the server is right there.
        $wanted = $request->has('in_watchlist')
            ? $request->boolean('in_watchlist')
            : ! $existing;

        if ($wanted === $existing) {
            // Already in the state asked for. Doing nothing is the correct
            // outcome for a replayed write.
            return response()->json([
                'inWatchlist' => $existing,
                'unchanged' => true,
            ]);
        }

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
