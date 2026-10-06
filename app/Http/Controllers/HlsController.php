<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Http\Controllers;

use App\Models\MediaItem;
use App\Services\ContentGate;
use App\Services\Streaming\HlsSegmenter;
use App\Services\Streaming\StreamPolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Serving a film as HLS when direct play will not do (S-29).
 *
 * Three steps, because that is what a player needs: ask how this item should
 * be played, fetch the playlist, then fetch segments as it watches.
 *
 * The decision endpoint exists so the client does not have to guess. It could
 * try direct play and fall back on failure, but "failure" for an undecodable
 * file is often a silent black rectangle rather than an error — the server
 * knows the codec and the caller's network, so it answers.
 */
class HlsController extends Controller
{
    public function __construct(
        private ContentGate $gate,
        private StreamPolicy $policy,
        private HlsSegmenter $segmenter,
    ) {}

    /**
     * How this item should be played for this caller.
     *
     * `{ transcode, reason, max_height, url }` — `url` is what to play either
     * way, so a client that trusts the server needs nothing else.
     */
    public function decide(Request $request, MediaItem $item): JsonResponse
    {
        abort_unless($this->gate->allows($item), 404);

        // Same reason as the API endpoint: the decision reads the probe.
        $item->loadMissing('probe');

        $decision = $this->policy->decide($item, $request);

        return response()->json([
            ...$decision->toArray(),
            'url' => $decision->transcode
                ? route('media.hls.playlist', ['item' => $item->id, 'from' => 0])
                : route('media.stream', $item),
        ]);
    }

    /**
     * The playlist, once the first segment exists.
     *
     * Waits rather than 404ing: the encode has just started, and a player told
     * "not found" gives up instead of retrying. A few seconds here is the
     * difference between playback starting and an error.
     */
    public function playlist(Request $request, MediaItem $item): Response
    {
        abort_unless($this->gate->allows($item), 404);

        // Same reason as the API endpoint: the decision reads the probe.
        $item->loadMissing('probe');

        $decision = $this->policy->decide($item, $request);
        $from = max(0.0, (float) $request->float('from'));

        $session = $this->segmenter->start(
            $item,
            $decision->maxHeight ?? (int) config('transcode.video.max_height', 1080),
            $from,
        );

        abort_if($session === null, 503, 'Could not start the stream.');

        $playlist = $this->waitForPlaylist($session);

        abort_if($playlist === null, 504, 'The stream did not start in time.');

        // Rewritten so each segment is fetched through this app rather than
        // from a path on disk the player cannot reach.
        //
        // Which route depends on who is asking. A browser carries its session
        // cookie on every segment, so the web route is right for it. A native
        // player does **not**: `AVURLAssetHTTPHeaderFieldsKey` applies to the
        // playlist request and AVFoundation does not propagate those headers
        // to the `.ts` fetches, so a segment arrives with no credentials at
        // all, the auth middleware answers `302 -> /login`, AVPlayer follows
        // it and decodes HTML as video. The result is a black picture with a
        // running clock, because the playlist (which did carry the header)
        // supplied the duration.
        //
        // So a token caller gets **signed** segment URLs instead: the
        // signature travels in the URL, which is the one thing the player
        // reliably keeps. The session id cannot do that job itself -- it is a
        // deterministic hash of (item, height, start), so it is guessable and
        // is not a credential.
        $signed = $this->signsSegments($request);

        $body = preg_replace_callback(
            '/^(seg\d+\.ts)$/m',
            fn (array $m): string => $signed
                ? URL::temporarySignedRoute(
                    'api.hls.segment',
                    now()->addHours(12),
                    ['session' => $session, 'file' => $m[1]],
                )
                : route('media.hls.segment', ['session' => $session, 'file' => $m[1]]),
            (string) file_get_contents($playlist),
        );

        return response((string) $body, 200, [
            'Content-Type' => 'application/vnd.apple.mpegurl',
            // The playlist grows as the encode advances, so a cached copy
            // would pin the player to the first few segments.
            'Cache-Control' => 'no-store',
        ]);
    }

    /** One segment. */
    /**
     * Whether this caller needs segment URLs that carry their own credentials.
     *
     * True for a token client, false for a browser. A cookie session rides
     * along on every segment request automatically; a bearer token does not,
     * because the player never sees it.
     *
     * Keyed on the request reaching an API route rather than on the guard, so
     * the web player is unaffected by anything that changes about tokens.
     */
    private function signsSegments(Request $request): bool
    {
        return $request->is('api/*');
    }

    public function segment(string $session, string $file): BinaryFileResponse
    {
        $path = $this->segmenter->fileIn($session, $file);

        abort_if($path === null, 404);

        return response()->file($path, [
            'Content-Type' => 'video/mp2t',
            // A segment never changes once written, so it is worth caching —
            // seeking backwards should not re-fetch what the player has.
            'Cache-Control' => 'public, max-age=31536000, immutable',
        ]);
    }

    /**
     * Waits for ffmpeg to write the playlist, up to a few seconds.
     *
     * The first segment has to be encoded before there is anything to serve,
     * which on a slow source is a second or two.
     */
    private function waitForPlaylist(string $session): ?string
    {
        $path = $this->segmenter->directoryFor($session).'/index.m3u8';

        for ($attempt = 0; $attempt < 60; $attempt++) {
            // Both: ffmpeg writes the playlist before the segment it names,
            // and a playlist naming a segment that is not there yet makes the
            // player request a 404 and stop.
            if (is_file($path) && glob(dirname($path).'/seg*.ts') !== []) {
                return $path;
            }

            usleep(250_000);
        }

        return null;
    }
}
