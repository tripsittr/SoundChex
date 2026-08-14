<?php

namespace App\Http\Controllers;

use App\Enums\MediaItemType;
use App\Models\MediaItem;
use App\Services\AlbumBrowser;
use App\Services\ContentGate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Albums as a browsable thing in their own right.
 *
 * An album is derived from track tags rather than stored, so it is addressed
 * by artist and title in the query string instead of by id. Names contain
 * slashes, question marks and colons often enough that a path segment would
 * mean escaping every one of them.
 */
class AlbumController extends Controller
{
    public function __construct(
        private AlbumBrowser $albums,
        private ContentGate $gate,
    ) {}

    /**
     * A shuffled queue drawn from the whole music library.
     *
     * Built on the server because the alternative is shipping 1,450 rows to
     * the page so the browser can pick from them. Capped rather than
     * unbounded: a queue of everything is not a feature anyone uses, and the
     * payload would be megabytes.
     *
     * Randomised in SQL rather than by fetching and shuffling, so the database
     * does the work and only the chosen rows are hydrated.
     */
    public function shuffleAll(): JsonResponse
    {
        $items = $this->gate
            ->apply(MediaItem::query())
            ->where('media_items.type', MediaItemType::Music)
            ->whereNotNull('media_items.file_path')
            ->inRandomOrder()
            ->limit(200)
            ->with('musicMetadata')
            ->get();

        return response()->json([
            'queue' => $items->map(fn (MediaItem $item) => $item->playerPayload())->values(),
        ]);
    }

    public function index(): View
    {
        return view('media.albums', [
            'albums' => $this->albums->paginate(),
        ]);
    }

    /**
     * Everything by one artist: their albums, then any loose tracks.
     */
    public function artist(Request $request): View
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:500'],
        ]);

        $albums = $this->albums->forArtist($data['name']);
        $singles = $this->albums->singlesForArtist($data['name']);

        // Nothing visible under this name means it does not exist as far as
        // this profile is concerned.
        abort_if($albums->isEmpty() && $singles->isEmpty(), 404);

        return view('media.artist', [
            'artist' => $data['name'],
            'albums' => $albums,
            'singles' => $singles,
        ]);
    }

    public function show(Request $request): View
    {
        $data = $request->validate([
            'artist' => ['required', 'string', 'max:500'],
            'album' => ['required', 'string', 'max:500'],
        ]);

        $tracks = $this->albums->tracks($data['artist'], $data['album']);

        // An album whose every track is hidden by the rating cap must read as
        // absent, not as an empty album — the difference tells a capped
        // profile exactly what it is being kept from.
        abort_if($tracks->isEmpty(), 404);

        return view('media.album', [
            'artist' => $data['artist'],
            'album' => $data['album'],
            'tracks' => $tracks,
            'multiDisc' => $this->albums->isMultiDisc($tracks),
            'duration' => $this->albums->duration($tracks),
        ]);
    }
}
