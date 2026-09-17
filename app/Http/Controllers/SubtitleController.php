<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Http\Controllers;

use App\Enums\MediaItemType;
use App\Models\MediaItem;
use App\Models\Subtitle;
use App\Services\Subtitles\OpenSubtitles;
use App\Services\Subtitles\SubtitleImporter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Caption tracks, as the player sees them.
 *
 * Online search is deliberately a user action rather than something the
 * library does on its own: OpenSubtitles rate-limits downloads per account,
 * and an automatic sweep across a library would exhaust that immediately.
 */
class SubtitleController extends Controller
{
    /**
     * Tracks available for a title.
     */
    public function index(MediaItem $item): JsonResponse
    {
        $this->assertVideo($item);

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
                    'source' => $track->source,
                    'forced' => $track->forced,
                    'sdh' => $track->sdh,
                    'default' => $track->is_default,
                    'url' => route('media.subtitle', ['item' => $item, 'subtitle' => $track]),
                ])
                ->values(),
        ]);
    }

    /**
     * Re-reads tracks that came with the file.
     *
     * Local only: no account, no network, no rate limit.
     */
    public function scan(MediaItem $item, SubtitleImporter $importer): JsonResponse
    {
        $this->assertVideo($item);

        if (! $importer->isAvailable()) {
            return response()->json([
                'status' => 'unavailable',
                'message' => 'ffmpeg is not installed on this server.',
            ], 422);
        }

        $result = $importer->importAll($item);

        return response()->json([
            'status' => 'complete',
            'embedded' => $result['embedded'],
            'sidecar' => $result['sidecar'],
        ]);
    }

    /**
     * Searches OpenSubtitles.
     */
    public function search(Request $request, MediaItem $item, OpenSubtitles $service): JsonResponse
    {
        $this->assertVideo($item);

        if (! $service->isConfigured()) {
            return response()->json([
                'status' => 'unconfigured',
                'message' => 'Add an OpenSubtitles API key under Settings → Metadata Sources to search online.',
            ], 422);
        }

        $languages = $request->string('languages')->trim()->value();

        $results = $service->search(
            $item,
            $languages !== ''
                ? array_slice(array_filter(explode(',', $languages)), 0, 5)
                : (array) config('subtitles.preferred_languages', ['en']),
        );

        return response()->json([
            'status' => 'complete',
            'results' => $results,
        ]);
    }

    /**
     * Downloads a chosen search result.
     */
    public function download(Request $request, MediaItem $item, OpenSubtitles $service): JsonResponse
    {
        $this->assertVideo($item);

        $data = $request->validate([
            'file_id' => ['required', 'integer'],
            'language' => ['nullable', 'string', 'max:12'],
            'forced' => ['nullable', 'boolean'],
            'hearing_impaired' => ['nullable', 'boolean'],
        ]);

        $subtitle = $service->download($item, (int) $data['file_id'], $data);

        if ($subtitle === null) {
            return response()->json([
                'status' => 'failed',
                'message' => 'That subtitle could not be downloaded.',
            ], 422);
        }

        return response()->json([
            'status' => 'complete',
            'subtitle' => [
                'id' => $subtitle->id,
                'label' => $subtitle->displayLabel(),
                'language' => $subtitle->language,
                'url' => route('media.subtitle', ['item' => $item, 'subtitle' => $subtitle]),
            ],
        ]);
    }

    public function destroy(MediaItem $item, Subtitle $subtitle): JsonResponse
    {
        $this->assertVideo($item);

        abort_unless($subtitle->media_item_id === $item->id, 404);

        $path = $subtitle->absolutePath();

        if ($path !== null) {
            @unlink($path);
        }

        $subtitle->delete();

        return response()->json(['deleted' => true]);
    }

    private function assertVideo(MediaItem $item): void
    {
        abort_unless(
            in_array($item->type, [MediaItemType::Movie, MediaItemType::Show], true),
            404,
        );
    }
}
