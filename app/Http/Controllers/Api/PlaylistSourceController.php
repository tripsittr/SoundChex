<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\ImportPlaylistJob;
use App\Models\PlaylistImport;
use App\Services\CurrentProfile;
use App\Services\Import\PlaylistImportService;
use App\Services\Import\Sources\PlaylistSource;
use App\Services\Import\Sources\PlaylistSourceRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * Porting playlists from a connected streaming service (S-312).
 *
 * The same import flow as a file (S-310) — parse to normalised tracks, match,
 * build a playlist — but the tracks come from a service the user has connected
 * rather than an uploaded file. Connecting is OAuth: `authorize` hands back the
 * consent URL, the service redirects to `callback`, and from then on `playlists`
 * and `import` work.
 */
class PlaylistSourceController extends Controller
{
    /** Above this many tracks, match on the queue rather than in the request. */
    private const INLINE_LIMIT = 40;

    public function __construct(
        private PlaylistSourceRegistry $registry,
        private PlaylistImportService $service,
    ) {}

    /**
     * The available services and, for each, whether it can be connected and
     * whether this user has.
     */
    public function index(): JsonResponse
    {
        $sources = array_map(fn (PlaylistSource $s): array => [
            'key' => $s->key(),
            'name' => $s->name(),
            'configured' => $s->isConfigured(),
            'connected' => $s->isConnected(),
        ], $this->registry->all());

        return response()->json(['sources' => $sources]);
    }

    /**
     * Begin connecting a source: returns the OAuth URL to open. `redirect_uri`
     * is where the service should return the user (the client provides it, since
     * it differs by platform).
     */
    public function authorize(Request $request, string $source): JsonResponse
    {
        $connector = $this->resolveConfigured($source);

        $data = $request->validate(['redirect_uri' => ['required', 'url']]);

        // A signed state carries the user through the round-trip so the callback
        // can only complete for the user who started it.
        $state = Str::random(40);
        cache()->put("playlist-oauth:{$state}", Auth::id(), now()->addMinutes(15));

        return response()->json([
            'url' => $connector->authorizationUrl($data['redirect_uri'], $state),
            'state' => $state,
        ]);
    }

    /**
     * Complete the connection from the code the service redirected back with.
     */
    public function callback(Request $request, string $source): JsonResponse
    {
        $connector = $this->resolveConfigured($source);

        $data = $request->validate([
            'code' => ['required', 'string'],
            'state' => ['required', 'string'],
            'redirect_uri' => ['required', 'url'],
        ]);

        // The state must be the one we issued to this user.
        abort_unless(
            (int) cache()->pull("playlist-oauth:{$data['state']}") === Auth::id(),
            422,
            'That connection request has expired. Please try again.',
        );

        try {
            $connector->connect($data['code'], $data['redirect_uri']);
        } catch (\Throwable $e) {
            return response()->json(['message' => 'Could not connect '.$connector->name().'.'], 422);
        }

        return response()->json(['connected' => true]);
    }

    /**
     * Forget a connected source.
     */
    public function disconnect(string $source): JsonResponse
    {
        $this->resolve($source)->disconnect();

        return response()->json(['connected' => false]);
    }

    /**
     * The connected user's playlists on a source, for them to pick one to import.
     */
    public function playlists(string $source): JsonResponse
    {
        $connector = $this->resolveConnected($source);

        return response()->json(['playlists' => $connector->playlists()]);
    }

    /**
     * Import one of the source's playlists into the library.
     */
    public function import(Request $request, string $source): JsonResponse
    {
        $connector = $this->resolveConnected($source);

        $data = $request->validate([
            'playlist_id' => ['required', 'string'],
            'name' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            $fetched = $connector->fetch($data['playlist_id']);
        } catch (\Throwable $e) {
            return response()->json(['message' => 'Could not read that playlist.'], 422);
        }

        $tracks = $fetched['tracks'];

        if ($tracks === []) {
            return response()->json(['message' => 'That playlist has no tracks.'], 422);
        }

        $name = $data['name'] ?? $fetched['name'];

        $import = PlaylistImport::create([
            'user_id' => Auth::id(),
            'source' => $connector->key(),
            'source_format' => $connector->key(),
            'name' => $name,
            'status' => PlaylistImport::STATUS_PENDING,
            'total_tracks' => count($tracks),
        ]);

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

        return response()->json([
            'id' => $import->fresh()->id,
            'status' => $import->fresh()->status,
            'total' => $import->fresh()->total_tracks,
            'matched' => $import->fresh()->matched_tracks,
        ], 201);
    }

    private function resolve(string $source): PlaylistSource
    {
        $connector = $this->registry->get($source);
        abort_if($connector === null, 404, 'Unknown source.');

        return $connector;
    }

    private function resolveConfigured(string $source): PlaylistSource
    {
        $connector = $this->resolve($source);
        abort_unless($connector->isConfigured(), 409, $connector->name().' is not set up on this server.');

        return $connector;
    }

    private function resolveConnected(string $source): PlaylistSource
    {
        $connector = $this->resolveConfigured($source);
        abort_unless($connector->isConnected(), 409, 'Connect '.$connector->name().' first.');

        return $connector;
    }
}
