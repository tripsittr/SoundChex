<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Http\Controllers;

use App\Models\Collection;
use App\Models\MediaItem;
use App\Services\ContentGate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
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
        $playlists = Collection::query()
            ->where('user_id', Auth::id())
            ->withCount('mediaItems')
            ->orderBy('name')
            ->get();

        return view('media.playlists', [
            'playlists' => $playlists,
            // Up to four track covers per playlist, for the mosaic fallback when
            // a playlist has no cover image of its own. Built in one grouped pass
            // rather than a query per card.
            'mosaics' => $this->mosaics($playlists),
        ]);
    }

    /**
     * Up to four cover URLs for each playlist, keyed by playlist id — the
     * mosaic fallback. One query for the pivot rows, one for the items, then
     * grouped in memory: no per-playlist query.
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

        $itemIds = $rows->pluck('media_item_id')->unique();
        $covers = MediaItem::whereIn('id', $itemIds)->get()
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
            // For the header cover's mosaic fallback when the playlist has no
            // image of its own — the first few tracks' covers, in order.
            'mosaic' => $tracks->map(fn (MediaItem $t) => $t->coverUrl())->filter()->take(4)->values(),
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

        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:120'],
            'description' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'cover' => ['sometimes', 'image', 'max:5120'], // 5 MB
        ]);

        // The cover is a file, not a fillable column — handle it separately and
        // replace any previous one so old files don't linger.
        if ($request->hasFile('cover')) {
            $old = $collection->artwork_path;
            $collection->artwork_path = $request->file('cover')->store('playlist-covers', 'public');
            if ($old && $old !== $collection->artwork_path) {
                Storage::disk('public')->delete($old);
            }
        }

        $collection->fill(array_intersect_key($data, array_flip(['name', 'description'])));
        $collection->save();

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
