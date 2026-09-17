<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Http\Controllers;

use App\Enums\MediaItemType;
use App\Models\MediaItem;
use App\Models\Subtitle;
use App\Services\ContentGate;
use Illuminate\Http\Response;
use Illuminate\View\View;

/**
 * Full-screen video playback for movies and shows.
 *
 * Streaming and progress reuse the media center's existing routes, so seeking
 * and resume behave identically to audio — only the chrome differs.
 */
class WatchController extends Controller
{
    public function show(MediaItem $item): View
    {
        abort_unless(
            in_array($item->type, [MediaItemType::Movie, MediaItemType::Show], true),
            404,
        );

        abort_unless($item->hasReadableFile(), 404);

        // MKV and AVI don't decode in any browser, so sending someone to a
        // player that shows a black rectangle helps nobody — the detail page
        // offers a download for those instead.
        abort_unless($item->isPlayableVideo(), 404);

        // Browse filtering is meaningless if a direct link still plays. A 404
        // rather than a 403: a restricted profile shouldn't be told what it's
        // missing.
        abort_unless(app(ContentGate::class)->allows($item), 404);

        return view('media.watch', [
            'item' => $item,
            'resumeAt' => $item->resumePosition(),
            'markers' => $item->skipMarkers(),
            'subtitles' => $item->subtitles()
                ->orderByDesc('is_default')
                ->orderBy('language')
                ->orderBy('forced')
                ->get()
                ->filter(fn (Subtitle $track): bool => $track->exists())
                ->values(),
            'counts' => [],
        ]);
    }

    /**
     * Serves one caption track.
     *
     * Subtitles live on the private disk alongside the video, so they're read
     * through here rather than linked directly. Browsers fetch a <track> src
     * with CORS rules of their own, hence the explicit content type.
     */
    public function subtitle(MediaItem $item, Subtitle $subtitle): Response
    {
        abort_unless($subtitle->media_item_id === $item->id, 404);

        $path = $subtitle->absolutePath();

        abort_unless($path !== null, 404);

        return response(file_get_contents($path), 200, [
            'Content-Type' => 'text/vtt; charset=UTF-8',
            // Tracks never change once converted, and a film may be re-opened
            // many times.
            'Cache-Control' => 'private, max-age=86400',
        ]);
    }
}
