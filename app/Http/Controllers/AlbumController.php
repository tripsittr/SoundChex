<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Http\Controllers;

use App\Enums\MediaItemType;
use App\Models\MediaItem;
use App\Models\Person;
use App\Services\AlbumBrowser;
use App\Services\ContentGate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
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
            // `plays` too: the shuffled queue is 200 payloads, each of which
            // asks for a resume position.
            ->with(['musicMetadata', 'plays'])
            ->get();

        return response()->json([
            'queue' => $items->map(fn (MediaItem $item) => $item->playerPayload())->values(),
        ]);
    }

    /**
     * Every music file this profile may download.
     *
     * The songs page is paginated, so a "download all" built from what is on
     * screen would silently take the first forty-eight tracks. This returns the
     * whole library through the same gate, which is what the button actually
     * means.
     *
     * Sizes come along so the client can total them and warn before starting
     * rather than filling the device part way through.
     */
    public function downloadableAll(): JsonResponse
    {
        $startedAt = microtime(true);

        $items = $this->gate
            ->apply(MediaItem::query())
            ->where('media_items.type', MediaItemType::Music)
            ->whereNotNull('media_items.file_path')
            ->orderBy('media_items.title')
            ->get();

        $queriedAt = microtime(true);

        $tracks = $items->map(fn (MediaItem $item) => [
            'id' => $item->id,
            'title' => $item->title,
            'size' => $item->playbackSize() ?? 0,
            'url' => route('media.stream', $item),
        ])->values();

        // The slowest thing this endpoint does is not the query — it is one
        // is_file() and one filesize() per track, several thousand times over.
        // Recorded separately so a slow response can be attributed rather than
        // guessed at: "Checking…" sitting on screen is the reported symptom and
        // this is where the time goes.
        Log::info('Downloadable library listed', [
            'tracks' => $tracks->count(),
            'query_ms' => (int) round(($queriedAt - $startedAt) * 1000),
            'sizing_ms' => (int) round((microtime(true) - $queriedAt) * 1000),
            'bytes' => $tracks->sum('size'),
        ]);

        return response()->json(['tracks' => $tracks]);
    }

    public function index(): View
    {
        return view('media.albums', [
            'albums' => $this->albums->paginate(),
        ]);
    }

    /**
     * Genre rails, on their own page.
     *
     * They used to sit above the songs list, where a dozen carousels pushed
     * the list itself off the screen.
     */
    public function genres(\App\Services\MediaBrowser $browser): View
    {
        $rows = collect($browser->rowsForType(MediaItemType::Music))
            ->filter(fn (array $row): bool => str_starts_with($row['key'] ?? '', 'genre-'))
            ->values()
            ->all();

        return view('media.genres', ['rows' => $rows]);
    }

    public function artists(): View
    {
        return view('media.artists', [
            'artists' => $this->albums->artists(),
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
        $appearsOn = $this->albums->appearsOn($data['name']);

        // Nothing visible under this name means it does not exist as far as
        // this profile is concerned. A guest artist with no records of their
        // own still has a page, because appearances are records too.
        abort_if($albums->isEmpty() && $singles->isEmpty() && $appearsOn->isEmpty(), 404);

        return view('media.artist', [
            'artist' => $data['name'],
            'albums' => $albums,
            'singles' => $singles,
            'appearsOn' => $appearsOn,
            // Null for most of a self-hosted library, and the page is built to
            // read the same without it.
            'profile' => Person::where('name', $data['name'])
                ->whereNotNull('profile_synced_at')
                ->first(),
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
