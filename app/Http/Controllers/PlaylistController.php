<?php

namespace App\Http\Controllers;

use App\Models\Collection;
use App\Models\MediaItem;
use App\Services\ContentGate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/**
 * Playlists, stored as Collections.
 *
 * Every method resolves the playlist through `owned()` rather than trusting
 * route-model binding. A Collection id is a small integer, so guessing another
 * household's playlist is trivial — the binding proves the row exists, not that
 * this account may touch it.
 */
class PlaylistController extends Controller
{
    public function __construct(private ContentGate $gate) {}

    public function index(): View
    {
        return view('media.playlists', [
            'playlists' => Collection::query()
                ->where('user_id', Auth::id())
                ->withCount('mediaItems')
                ->orderBy('name')
                ->get(),
        ]);
    }

    public function show(Collection $collection): View
    {
        $this->owned($collection);

        // Gated per track, not per playlist: a capped profile sees the
        // playlist minus what it may not play, rather than being refused the
        // whole thing or — worse — shown everything.
        $tracks = $this->gate
            ->apply($collection->mediaItems()->getQuery())
            // `plays` too: every track here becomes a player payload, which
            // asks for a resume position.
            ->with(['musicMetadata', 'plays'])
            ->orderBy('collection_media_item.sort_order')
            ->get();

        return view('media.playlist', [
            'playlist' => $collection,
            'tracks' => $tracks,
        ]);
    }

    public function store(Request $request): RedirectResponse
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

        return redirect()
            ->route('media.playlist', $playlist)
            ->with('status', 'Playlist created.');
    }

    public function update(Request $request, Collection $collection): RedirectResponse
    {
        $this->owned($collection);

        $collection->update($request->validate([
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:1000'],
        ]));

        return back()->with('status', 'Playlist updated.');
    }

    public function destroy(Collection $collection): RedirectResponse
    {
        $this->owned($collection);

        // Only the playlist goes. Detaching leaves every track exactly where
        // it was — deleting a playlist must never delete music.
        $collection->mediaItems()->detach();
        $collection->delete();

        return redirect()
            ->route('media.playlists')
            ->with('status', 'Playlist deleted.');
    }

    public function addItem(Request $request, Collection $collection): JsonResponse
    {
        $this->owned($collection);

        $data = $request->validate([
            'item_id' => ['required', 'integer', 'exists:media_items,id'],
        ]);

        $item = MediaItem::findOrFail($data['item_id']);

        // Without this a capped profile could add a blocked track and then
        // play it from the playlist, where the per-track gate would already
        // have been satisfied at insert time.
        abort_unless($this->gate->allows($item), 404);

        // Appended, so adding never reorders what is already there.
        $next = (int) $collection->mediaItems()->max('sort_order') + 1;

        $collection->mediaItems()->syncWithoutDetaching([
            $item->id => ['sort_order' => $next],
        ]);

        return response()->json([
            'added' => true,
            'count' => $collection->mediaItems()->count(),
        ]);
    }

    public function removeItem(Request $request, Collection $collection, MediaItem $item): JsonResponse|RedirectResponse
    {
        $this->owned($collection);

        $collection->mediaItems()->detach($item->id);

        // The playlist page removes with a plain form, so a JSON body would
        // render as raw text in the browser. Scripted callers still get JSON.
        return $request->expectsJson()
            ? response()->json(['removed' => true, 'count' => $collection->mediaItems()->count()])
            : back()->with('status', 'Removed from playlist.');
    }

    public function reorder(Request $request, Collection $collection): JsonResponse
    {
        $this->owned($collection);

        $data = $request->validate([
            'order' => ['required', 'array'],
            'order.*' => ['integer', 'exists:media_items,id'],
        ]);

        foreach ($data['order'] as $position => $itemId) {
            $collection->mediaItems()->updateExistingPivot($itemId, [
                'sort_order' => $position,
            ]);
        }

        return response()->json(['reordered' => true]);
    }

    /**
     * Refuses a playlist belonging to another account.
     *
     * 404 rather than 403: a household should not be able to learn that
     * another one's playlist exists by watching the status code change.
     */
    private function owned(Collection $collection): void
    {
        abort_unless($collection->user_id === Auth::id(), 404);
    }
}
