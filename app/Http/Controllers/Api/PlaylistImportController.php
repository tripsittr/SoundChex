<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\ImportPlaylistJob;
use App\Models\MediaItem;
use App\Models\PlaylistImport;
use App\Services\ContentGate;
use App\Services\CurrentProfile;
use App\Services\Import\PlaylistImportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Porting a playlist into the library from a file (S-310).
 *
 * The client uploads an M3U / CSV / XSPF, this parses and matches it, and a
 * playlist of the tracks the library has is created. A short playlist is matched
 * in the request and returned complete; a long one is queued, and the client
 * polls `show`. Unmatched tracks come back as data for the user to resolve.
 */
class PlaylistImportController extends Controller
{
    /** Above this many tracks, match on the queue rather than in the request. */
    private const INLINE_LIMIT = 40;

    public function __construct(
        private PlaylistImportService $service,
        private ContentGate $gate,
    ) {}

    /**
     * Upload a playlist file and start (or finish) the port.
     */
    public function store(Request $request): JsonResponse
    {
        // A playlist file is small text (M3U/CSV/XSPF). The MIME of these varies
        // wildly by exporter and OS, so rather than an unreliable mimetypes rule,
        // the size is capped and the *contents* are validated by parsing below —
        // an unrecognised file is rejected with a clear message, not a 422 on type.
        $data = $request->validate([
            'file' => ['required', 'file', 'max:5120'],
            'name' => ['nullable', 'string', 'max:255'],
        ]);

        $file = $request->file('file');
        $contents = (string) file_get_contents($file->getRealPath());
        $extension = strtolower($file->getClientOriginalExtension());

        try {
            $parsed = $this->service->parseFile($contents, $extension);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $tracks = $parsed['tracks'];

        if ($tracks === []) {
            return response()->json(['message' => 'No tracks found in that file.'], 422);
        }

        $name = $data['name'] ?? $parsed['name'] ?? pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);

        $import = PlaylistImport::create([
            'user_id' => Auth::id(),
            'source' => 'file',
            'source_format' => $parsed['format'],
            'name' => $name,
            'status' => PlaylistImport::STATUS_PENDING,
            'total_tracks' => count($tracks),
        ]);

        // A short playlist is matched now, so the client gets the result in one
        // call; a long one is queued to keep the request fast.
        if (count($tracks) <= self::INLINE_LIMIT) {
            $this->service->run($import, $tracks, $name);
        } else {
            ImportPlaylistJob::dispatch(
                $import->id,
                array_map(fn ($t) => $t->toArray(), $tracks),
                $name,
                app(CurrentProfile::class)->id(),
            );
        }

        return response()->json($this->payload($import->fresh()), 201);
    }

    /**
     * The state of an import — for polling a queued one, and for the final
     * matched/unmatched result.
     */
    public function show(PlaylistImport $import): JsonResponse
    {
        $this->authorizeOwner($import);

        return response()->json($this->payload($import));
    }

    /**
     * Resolve an unmatched track by attaching a chosen library item to the
     * import's playlist, and drop it from the unmatched list.
     */
    public function resolve(Request $request, PlaylistImport $import): JsonResponse
    {
        $this->authorizeOwner($import);

        $data = $request->validate([
            'index' => ['required', 'integer', 'min:0'],
            'item_id' => ['required', 'integer'],
        ]);

        $collection = $import->collection;
        abort_if($collection === null, 409, 'This import has no playlist yet.');

        $unmatched = $import->unmatched ?? [];
        abort_unless(array_key_exists($data['index'], $unmatched), 404, 'No such unmatched track.');

        $item = MediaItem::find($data['item_id']);
        abort_unless($item !== null && $this->gate->allows($item), 404);

        $next = (int) $collection->mediaItems()->max('sort_order') + 1;
        $collection->mediaItems()->syncWithoutDetaching([$item->id => ['sort_order' => $next]]);

        // Drop the resolved entry and re-index.
        unset($unmatched[$data['index']]);
        $import->update([
            'unmatched' => array_values($unmatched),
            'matched_tracks' => $import->matched_tracks + 1,
        ]);

        return response()->json($this->payload($import->fresh()));
    }

    /**
     * The account's imports, newest first — a small history.
     */
    public function index(): JsonResponse
    {
        $imports = PlaylistImport::query()
            ->where('user_id', Auth::id())
            ->latest('id')
            ->limit(50)
            ->get()
            ->map(fn (PlaylistImport $i): array => $this->payload($i));

        return response()->json(['imports' => $imports]);
    }

    private function authorizeOwner(PlaylistImport $import): void
    {
        abort_unless($import->user_id === Auth::id(), 404);
    }

    /**
     * The import as the client reads it.
     */
    private function payload(PlaylistImport $import): array
    {
        return [
            'id' => $import->id,
            'name' => $import->name,
            'source' => $import->source,
            'format' => $import->source_format,
            'status' => $import->status,
            'total' => $import->total_tracks,
            'matched' => $import->matched_tracks,
            'unmatched' => array_values($import->unmatched ?? []),
            'playlist_id' => $import->collection_id,
            'error' => $import->error,
        ];
    }
}
