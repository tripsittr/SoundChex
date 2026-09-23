<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Http\Controllers\Api;

use App\Events\PlaybackCompleted;
use App\Events\PlaybackProgress;
use App\Events\PlaybackRecorded;
use App\Http\Controllers\Controller;
use App\Http\Controllers\MediaCenterController;
use App\Http\Resources\MediaItemResource;
use App\Models\MediaItem;
use App\Models\MediaPlay;
use App\Services\ContentGate;
use App\Services\CurrentProfile;
use App\Services\LyricsService;
use App\Services\SearchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;

/**
 * The client-facing media API for the native app.
 *
 * The web app serves streaming, progress and search behind the session guard
 * (routes/web.php), which a token-only native client cannot reach. These are the
 * same operations under `auth:sanctum`, reusing the models and services the web
 * controllers use so behaviour — the content-rating gate, range streaming, the
 * offline-replay staleness rule — stays identical across the two front ends.
 *
 * The completion fraction and the listened-time step are read from
 * MediaCenterController rather than restated here: they are the same rule, and
 * a copy that drifted would make one front end call a film watched while the
 * other still offered to resume it.
 */
class MediaController extends Controller
{
    /**
     * The media bytes, with HTTP Range support so the app can seek.
     *
     * Identical to MediaCenterController@stream but reached with a bearer token.
     * A `BinaryFileResponse` sets Accept-Ranges and answers a ranged request with
     * 206 Partial Content, which is what lets AVPlayer scrub without refetching.
     */
    public function stream(MediaItem $item): BinaryFileResponse
    {
        abort_unless(app(ContentGate::class)->allows($item), 404);

        $path = $item->playbackPath();

        abort_unless($path !== null, 404);

        $this->recordPlay($item);

        return response()->file($path, [
            // makeDisposition percent-encodes the title and supplies an ASCII
            // fallback, so a newline in a tag cannot inject a header.
            'Content-Disposition' => (new ResponseHeaderBag)->makeDisposition(
                ResponseHeaderBag::DISPOSITION_INLINE,
                (string) $item->title,
                'media',
            ),
        ]);
    }

    /**
     * The resume position for an item, for this profile.
     *
     * The web app never needed a read endpoint — the position rode along in the
     * page it rendered. A native player has no such page, so it asks here before
     * playing to resume where it stopped.
     */
    public function progress(MediaItem $item): JsonResponse
    {
        $play = $this->recentPlay($item);

        return response()->json([
            'position' => (int) ($play?->position_seconds ?? 0),
            'completed' => (bool) ($play?->completed ?? false),
        ]);
    }

    /**
     * Records a playback position. Mirrors MediaCenterController@saveProgress,
     * including the offline-replay staleness guard and listened-time accounting,
     * so the same client logic drives both front ends.
     */
    public function saveProgress(Request $request, MediaItem $item): JsonResponse
    {
        $data = $request->validate([
            'position' => ['required', 'integer', 'min:0'],
            'duration' => ['nullable', 'integer', 'min:0'],
            'recorded_at' => ['nullable', 'date'],
        ]);

        $play = $this->recentPlay($item);

        $duration = $data['duration'] ?? 0;
        $completed = $duration > 0 && $data['position'] >= $duration * MediaCenterController::COMPLETE_FRACTION;

        // A replayed (offline-queued) write applies only if it is newer than
        // what is stored, or it would undo progress made since. Checked before
        // the row is created — creating first would stamp updated_at with "now".
        $recordedAt = isset($data['recorded_at'])
            ? Carbon::parse($data['recorded_at'])
            : now();

        if ($play !== null && $play->updated_at !== null && $recordedAt->lt($play->updated_at)) {
            return response()->json([
                'completed' => (bool) $play->completed,
                'stale' => true,
            ]);
        }

        $profileId = app(CurrentProfile::class)->id();

        if ($play === null) {
            $play = $item->plays()->create([
                'user_id' => Auth::id(),
                'profile_id' => $profileId,
            ]);
        }

        // Only forward movement small enough to be playback counts as listened,
        // so a scrub to the end does not bank the whole track.
        $advanced = $data['position'] - (int) ($play->position_seconds ?? 0);
        $listened = ($advanced > 0 && $advanced <= MediaCenterController::LISTENED_MAX_STEP) ? $advanced : 0;

        $wasCompleted = (bool) $play->completed;

        $play->forceFill([
            'position_seconds' => $data['position'],
            'listened_seconds' => ($play->listened_seconds ?? 0) + $listened,
            'completed' => $completed,
        ])->save();

        // A resume point was saved — fires on every progress write, matching
        // the web endpoint so both front ends emit the same signal (S-285).
        PlaybackProgress::dispatch($item, $profileId, $data['position']);

        // Fire once, when it crosses into complete — the "watched"/"scrobble"
        // signal a tracker plugin reports at the end, distinct from the play
        // start (S-276).
        if ($completed && ! $wasCompleted) {
            PlaybackCompleted::dispatch($item, $profileId);
        }

        return response()->json(['completed' => $completed]);
    }

    /**
     * Search the library, as JSON.
     *
     * Wraps SearchService — the same engine the web search page uses, which
     * covers titles, people, dialogue, book text and tags — and flattens its
     * grouped result into the media items the app renders. The service applies
     * the content gate itself, so a capped profile never sees a gated hit.
     */
    public function search(Request $request, SearchService $search): JsonResponse
    {
        $term = (string) $request->query('q', '');

        $result = $search->search($term);

        // Collect the MediaItems out of every group that carries them (titles,
        // dialogue, pages, and each person's own items), de-duplicated by id and
        // capped so a broad term cannot return the whole library.
        $items = collect($result['groups'])
            ->flatMap(function (array $group): iterable {
                return collect($group['results'])->flatMap(function ($row): iterable {
                    // A row that carries a single item — a title, a dialogue cue,
                    // a book page (kinds 'item', 'cue', 'page'). Keyed off the
                    // item being present rather than the kind, so a dialogue or
                    // page hit is not silently dropped (the app then never sees
                    // that track, and cannot play it).
                    if (($row['item'] ?? null) instanceof MediaItem) {
                        return [$row['item']];
                    }

                    // People and other grouped rows carry their own items.
                    return collect($row['items'] ?? $row['results'] ?? [])
                        ->filter(fn ($candidate): bool => $candidate instanceof MediaItem);
                });
            })
            ->unique('id')
            ->take(60)
            ->values();

        return response()->json([
            'query' => $result['query'],
            'items' => MediaItemResource::collection($items),
        ]);
    }

    /**
     * The lyrics for a track, fetched and cached from a provider on first ask.
     *
     * Only music, and only what the content gate allows. Returns
     * `{ lyrics, synced }`: `lyrics` is the plain words (null when the track has
     * none), `synced` is time-synced LRC text for the scroll-highlight (null when
     * only unsynced words exist). The app shows the section only when there is
     * something to show, so nulls are a normal answer, not an error. `lyrics`
     * stays for older clients that read only that key.
     */
    public function lyrics(MediaItem $item, LyricsService $lyrics): JsonResponse
    {
        abort_unless(app(ContentGate::class)->allows($item), 404);

        $payload = $lyrics->lyricsPayloadFor($item);

        return response()->json([
            'lyrics' => $payload['plain'],
            'synced' => $payload['synced'],
        ]);
    }

    // MARK: - Shared helpers (the same shape MediaCenterController uses)

    /** The recent play row for this item and profile, or null. */
    private function recentPlay(MediaItem $item): ?MediaPlay
    {
        $userId = Auth::id();
        $profileId = app(CurrentProfile::class)->id();

        return $item->plays()
            ->when($profileId, fn ($query) => $query->where('profile_id', $profileId))
            ->when(! $profileId, fn ($query) => $query->where('user_id', $userId))
            ->where('updated_at', '>=', now()->subHours(6))
            ->latest('id')
            ->first();
    }

    /** One play row per listening session, not per position update. */
    private function recordPlay(MediaItem $item): void
    {
        $userId = Auth::id();
        $profileId = app(CurrentProfile::class)->id();

        $recent = $item->plays()
            ->where('user_id', $userId)
            ->when($profileId, fn ($query) => $query->where('profile_id', $profileId))
            ->where('created_at', '>=', now()->subMinutes(10))
            ->exists();

        if ($recent) {
            return;
        }

        $from = request()->query('from');
        $source = is_string($from) && in_array($from, MediaPlay::SOURCES, true) ? $from : null;

        $item->plays()->create([
            'user_id' => $userId,
            'profile_id' => $profileId,
            'source' => $source,
        ]);

        // The native app's plays fired no event, while the web player's did — a
        // scrobbler plugin saw web listens and not app ones. Dispatch here too
        // (S-276).
        PlaybackRecorded::dispatch($item, $profileId);
    }
}
