<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\MediaItemResource;
use App\Models\Collection;
use App\Models\MediaItem;
use App\Models\Scopes\ResolvedScope;
use App\Services\ContentGate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Playlists for the native app.
 *
 * The same Collections the web app manages (routes/web.php, session-guarded),
 * exposed under `auth:sanctum` for a token client. Playlists belong to the
 * account (user_id), so every profile on it shares them; tracks are gated
 * per-item, so a capped profile sees a playlist minus what it may not play
 * rather than being refused the whole thing.
 */
class PlaylistController extends Controller
{
    public function __construct(private ContentGate $gate) {}

    /** The account's playlists, with their track counts and cover, for a list. */
    public function index(): JsonResponse
    {
        $collections = Collection::query()
            ->where('user_id', Auth::id())
            ->withCount('mediaItems')
            ->orderBy('name')
            ->get();

        $mosaics = $this->mosaics($collections);

        $playlists = $collections->map(fn (Collection $c): array => [
            'id' => $c->id,
            'name' => $c->name,
            'description' => $c->description,
            'count' => $c->media_items_count,
            'artwork_url' => $c->artworkUrl(),
            // The covers of the first few tracks, for the client to draw a
            // mosaic when the playlist has no cover of its own (S-371). Sent
            // from here because the list endpoint carries no tracks: without
            // it every playlist without an uploaded cover drew a note glyph,
            // while the detail screen — which does have the tracks — showed a
            // mosaic, so covers appeared only inside a playlist.
            'mosaic' => $mosaics[$c->id] ?? [],
        ]);

        return response()->json(['playlists' => $playlists]);
    }

    /**
     * Up to four track cover URLs for each playlist, keyed by playlist id.
     *
     * One query for the pivot rows and one for the items, then grouped in
     * memory — a query per playlist would make the list cost grow with the
     * number of playlists, and this endpoint is the app's first screen.
     *
     * @param  \Illuminate\Support\Collection<int, Collection>  $playlists
     * @return array<int, array<int, string>>
     */
    private function mosaics(\Illuminate\Support\Collection $playlists): array
    {
        $ids = $playlists->pluck('id');

        if ($ids->isEmpty()) {
            return [];
        }

        $rows = DB::table('collection_media_item')
            ->whereIn('collection_id', $ids)
            ->orderBy('sort_order')
            ->get(['collection_id', 'media_item_id']);

        $covers = MediaItem::query()
            ->whereIn('id', $rows->pluck('media_item_id')->unique())
            ->get()
            ->mapWithKeys(fn (MediaItem $i) => [$i->id => $i->coverUrl()]);

        $out = [];

        foreach ($rows as $row) {
            $url = $covers[$row->media_item_id] ?? null;

            if ($url === null) {
                continue;
            }

            $out[$row->collection_id] ??= [];

            if (count($out[$row->collection_id]) < 4) {
                $out[$row->collection_id][] = $url;
            }
        }

        return $out;
    }

    /** One playlist and its tracks, gated per item and in playlist order. */
    public function show(Collection $collection): JsonResponse
    {
        $this->owned($collection);

        $tracks = $this->gate
            ->apply($collection->mediaItems()->getQuery())
            ->with('musicMetadata')
            ->orderBy('collection_media_item.sort_order')
            ->get();

        // Total run time is a per-item sum after gating, not a stored figure, so
        // a capped profile's total reflects only the tracks it can actually play.
        $durationMs = $tracks->sum(fn (MediaItem $t): int => (int) ($t->musicMetadata?->duration_ms ?? 0));

        // Tracks the playlist holds that the library is currently hiding —
        // awaiting review, or their file is missing (S-396). Reported as a
        // count so the client can say "1 track unavailable" rather than just
        // showing eleven of twelve: a rating cap is permanent and silence
        // suits it, but an unresolved track is *temporarily* absent and a
        // gap with no explanation reads as data loss.
        //
        // Counted off the pivot rather than the relation: the relation carries
        // the same global scope that hid the tracks, so comparing it with
        // `$tracks` would always give zero. The pivot is the only place that
        // still knows how many tracks the playlist was built with.
        $unavailable = $collection->mediaItems()
            ->withoutGlobalScope(ResolvedScope::class)
            ->count() - $tracks->count();

        return response()->json([
            'id' => $collection->id,
            'name' => $collection->name,
            'description' => $collection->description,
            'artwork_url' => $collection->artworkUrl(),
            'count' => $tracks->count(),
            'unavailable_count' => max(0, $unavailable),
            'duration_ms' => $durationMs,
            'items' => MediaItemResource::collection($tracks),
        ]);
    }

    /** Creates a playlist. */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:1000'],
        ]);

        $playlist = Collection::create([
            'user_id' => Auth::id(),
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
        ]);

        return response()->json([
            'id' => $playlist->id,
            'name' => $playlist->name,
            'description' => $playlist->description,
            'count' => 0,
            'artwork_url' => null,
        ], 201);
    }

    /** Renames a playlist and/or edits its description. */
    public function update(Request $request, Collection $collection): JsonResponse
    {
        $this->owned($collection);

        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:120'],
            'description' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ]);

        $collection->update($data);

        return response()->json([
            'id' => $collection->id,
            'name' => $collection->name,
            'description' => $collection->description,
            'artwork_url' => $collection->artworkUrl(),
        ]);
    }

    /**
     * Reorders a playlist. The body carries the full ordered list of item ids;
     * each item's `sort_order` is set to its position, so a drag-to-reorder on
     * the client persists in one request.
     *
     * Only ids already on the playlist are touched — a stray id is ignored
     * rather than added, so this can never smuggle a track past the add gate.
     */
    public function reorder(Request $request, Collection $collection): JsonResponse
    {
        $this->owned($collection);

        $data = $request->validate([
            'order' => ['required', 'array'],
            'order.*' => ['integer'],
        ]);

        foreach (array_values($data['order']) as $position => $itemId) {
            $collection->mediaItems()->updateExistingPivot($itemId, [
                'sort_order' => $position,
            ]);
        }

        return response()->json(['reordered' => true]);
    }

    /**
     * Sets a playlist's cover image. Replaces any previous one, so the old file
     * doesn't linger on disk. Kept small — a cover is displayed, not printed.
     */
    public function uploadCover(Request $request, Collection $collection): JsonResponse
    {
        $this->owned($collection);

        $request->validate([
            'cover' => ['required', 'image', 'max:5120'], // 5 MB
        ]);

        $old = $collection->artwork_path;

        $path = $request->file('cover')->store('playlist-covers', 'public');

        $collection->update(['artwork_path' => $path]);

        if ($old && $old !== $path) {
            Storage::disk('public')->delete($old);
        }

        return response()->json([
            'id' => $collection->id,
            'artwork_url' => $collection->artworkUrl(),
        ]);
    }

    /** Appends an item to a playlist. */
    public function addItem(Request $request, Collection $collection): JsonResponse
    {
        $this->owned($collection);

        $data = $request->validate([
            'item_id' => ['required', 'integer', 'exists:media_items,id'],
        ]);

        $item = MediaItem::findOrFail($data['item_id']);

        // A capped profile must not be able to add a blocked track and then play
        // it from the playlist, where the per-track gate is already satisfied.
        abort_unless($this->gate->allows($item), 404);

        // A playlist cannot hold the same track twice — the pivot's primary key
        // is (collection_id, media_item_id) — so re-adding one silently
        // rewrote its sort_order and moved it to the end. Say so instead, and
        // let the client decide (S-373).
        $already = $collection->mediaItems()->whereKey($item->id)->exists();

        if ($already && ! $request->boolean('move_to_end')) {
            return response()->json([
                'added' => false,
                'already_present' => true,
                'count' => $collection->mediaItems()->count(),
                'message' => 'That song is already in this playlist.',
            ], 409);
        }

        $next = (int) $collection->mediaItems()->max('sort_order') + 1;

        $collection->mediaItems()->syncWithoutDetaching([
            $item->id => ['sort_order' => $next],
        ]);

        return response()->json([
            'added' => true,
            'already_present' => $already,
            'moved' => $already,
            'count' => $collection->mediaItems()->count(),
        ]);
    }

    /** Removes an item from a playlist. The track itself is untouched. */
    public function removeItem(Collection $collection, MediaItem $item): JsonResponse
    {
        $this->owned($collection);

        $collection->mediaItems()->detach($item->id);

        return response()->json([
            'removed' => true,
            'count' => $collection->mediaItems()->count(),
        ]);
    }

    /** Deletes a playlist. Detaches first, so deleting a playlist never deletes music. */
    public function destroy(Collection $collection): JsonResponse
    {
        $this->owned($collection);

        $collection->mediaItems()->detach();
        $collection->delete();

        return response()->json(['deleted' => true]);
    }

    /**
     * 404 for a playlist on another account — a household should not learn that
     * another one's playlist exists by watching the status code.
     */
    private function owned(Collection $collection): void
    {
        abort_unless($collection->user_id === Auth::id(), 404);
    }
}
