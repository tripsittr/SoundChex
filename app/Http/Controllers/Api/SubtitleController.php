<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Http\Controllers\Api;

use App\Enums\MediaItemType;
use App\Http\Controllers\Controller;
use App\Models\MediaItem;
use App\Models\Subtitle;
use App\Services\ContentGate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

/**
 * Subtitle tracks for the native app (S-160).
 *
 * The web serves these too, but through session-authed routes the app cannot
 * reach; this is the token-authed equivalent so the iOS video player can list a
 * film's caption tracks and load one. Every access passes the same ContentGate
 * the stream does — a capped profile cannot pull subtitles for a film it may not
 * play, which would leak the film's existence and its dialogue.
 */
class SubtitleController extends Controller
{
    /**
     * The available subtitle tracks for a video, newest/default first.
     */
    public function index(MediaItem $item): JsonResponse
    {
        $this->assertPlayableVideo($item);

        return response()->json([
            'subtitles' => $item->subtitles()
                ->orderByDesc('is_default')
                ->orderBy('language')
                ->get()
                ->filter(fn (Subtitle $track): bool => $track->exists())
                ->map(fn (Subtitle $track): array => [
                    'id' => $track->id,
                    'label' => $track->displayLabel(),
                    'language' => $track->language,
                    'forced' => (bool) $track->forced,
                    'sdh' => (bool) $track->sdh,
                    'default' => (bool) $track->is_default,
                    'url' => route('api.items.subtitle', ['item' => $item, 'subtitle' => $track]),
                ])
                ->values(),
        ]);
    }

    /**
     * One track's WebVTT content, for the player to render.
     */
    public function show(MediaItem $item, Subtitle $subtitle): Response
    {
        $this->assertPlayableVideo($item);

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

    /**
     * A video the current profile is allowed to play. 404 rather than 403 for a
     * gated item, so a capped profile cannot tell a blocked film from a missing
     * one.
     */
    private function assertPlayableVideo(MediaItem $item): void
    {
        abort_unless(
            in_array($item->type, [MediaItemType::Movie, MediaItemType::Show], true),
            404,
        );

        abort_unless(app(ContentGate::class)->allows($item), 404);
    }
}
