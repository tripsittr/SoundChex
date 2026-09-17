<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\MediaItemResource;
use App\Models\Collection;
use App\Models\MediaItem;
use App\Services\ContentGate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

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

    /** The account's playlists, with their track counts, for a picker. */
    public function index(): JsonResponse
    {
        $playlists = Collection::query()
            ->where('user_id', Auth::id())
            ->withCount('mediaItems')
            ->orderBy('name')
            ->get()
            ->map(fn (Collection $c): array => [
                'id' => $c->id,
                'name' => $c->name,
                'description' => $c->description,
                'count' => $c->media_items_count,
            ]);

        return response()->json(['playlists' => $playlists]);
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

        return response()->json([
            'id' => $collection->id,
            'name' => $collection->name,
            'description' => $collection->description,
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
            'count' => 0,
        ], 201);
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

        $next = (int) $collection->mediaItems()->max('sort_order') + 1;

        $collection->mediaItems()->syncWithoutDetaching([
            $item->id => ['sort_order' => $next],
        ]);

        return response()->json([
            'added' => true,
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
