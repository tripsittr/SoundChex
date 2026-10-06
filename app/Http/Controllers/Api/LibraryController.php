<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Http\Controllers\Api;

use App\Enums\MediaItemType;
use App\Http\Controllers\Controller;
use App\Http\Resources\MediaItemResource;
use App\Models\MediaItem;
use App\Services\ContentGate;
use App\Services\CurrentProfile;
use App\Services\MediaBrowser;
use App\Services\ResumeFrames;
use App\Services\SmartShuffle;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * The catalogue, for the device to mirror.
 *
 * The whole library is sent rather than paged: measured at 0.8 MB of JSON —
 * 76 KB gzipped — for 1,450 items, which is well under a second and lets the
 * device hold a complete copy. Browsing offline then needs no network at all,
 * which paging would make impossible.
 *
 * **This is a bulk export, which makes the content gate load-bearing.** A
 * missed filter here does not leak one page to a capped profile; it leaks the
 * entire library in a single request, to a device that then keeps it. The gate
 * is applied in one place, `visible()`, and every endpoint goes through it.
 */
class LibraryController extends Controller
{
    /**
     * How many frames one request may extract for a cold shelf (#513).
     *
     * Each is a synchronous ffmpeg call in front of a waiting client, so the
     * first load of a new shelf fills in a few and later loads fill the rest
     * from cache. Four keeps the added time to roughly half a second on the
     * measured file while still making the row visibly different at once.
     */
    private const FRAMES_PER_REQUEST = 4;

    /*
     * Deliberately no constructor injection.
     *
     * A controller is instantiated before the auth middleware has run, so a
     * CurrentProfile resolved here caches its answer — null — and every later
     * call returns it. The gate then filters nothing and the ETag carries a
     * profile id of 0, so a capped device both receives the whole library and
     * shares a cache entry with the owner.
     *
     * Both are resolved per call instead, after authentication.
     */
    private function gate(): ContentGate
    {
        return app(ContentGate::class);
    }

    private function profiles(): CurrentProfile
    {
        return app(CurrentProfile::class);
    }

    /**
     * A shuffled queue of the music library (S-289).
     *
     * `smart=1` weights the draw by what this profile actually plays, rather
     * than treating a library of thousands uniformly — the third state of the
     * shuffle button. Without it, an ordinary uniform shuffle.
     *
     * Built here rather than on the device: the phone would have to hold the
     * whole library and the whole play history to weight anything.
     */
    /**
     * What the person has started and not finished (S-414).
     *
     * The web home has had this for a long time — it is the row that makes a
     * home page feel like *yours* rather than a catalogue — but it was never
     * exposed to the apps, so the phone's home page could only ever show
     * "recently added" and one row per type.
     *
     * Built here rather than on the device because resume position lives in
     * `media_plays` and the library mirror does not carry it. Sending every
     * play row to every device to let it work this out itself would be a lot
     * of history for one row.
     *
     * Films, shows and books in one response: the home page wants one
     * "continue" shelf, not three, and the client should not have to make
     * three calls to build it.
     */
    public function continueItems(Request $request, MediaBrowser $browser): JsonResponse
    {
        $limit = (int) min(50, max(1, (int) $request->integer('limit', 20)));

        $watching = $browser->continueWatching($limit);
        $reading = $browser->continueReading($limit);

        return response()->json([
            // The frame each viewer stopped on, keyed by item id (#513). A
            // poster says which film it is; the frame says where you are in
            // it, which is the only question this row asks.
            //
            // Sent beside the items rather than replacing `artwork`, so a
            // client that has not been updated still renders posters and a
            // client that has can fall back when a frame is absent.
            'resume_frames' => $this->resumeFrames($watching),
            // Seconds into each item, keyed by item id.
            //
            // The shelf already knew this -- it is what picks the frame -- but
            // only the frame was sent, so a client could show the right still
            // and still not draw a progress bar or say "18m remaining". Sent
            // as its own map for the same reason as the frames: an older
            // client ignores a key it does not know.
            'resume_positions' => $watching
                ->filter(fn ($item) => (int) ($item->resume_position ?? 0) > 0)
                ->mapWithKeys(fn ($item) => [$item->id => (int) $item->resume_position])
                ->all(),
            'watching' => MediaItemResource::collection($watching),
            'reading' => MediaItemResource::collection($reading),
        ]);
    }

    public function shuffle(Request $request): JsonResponse
    {
        $limit = min(max((int) $request->integer('limit', 200), 1), 500);

        $items = $request->boolean('smart')
            ? app(SmartShuffle::class)->queue($this->profiles()->get()?->id, $limit)
            : $this->gate()
                ->apply(MediaItem::query())
                ->where('media_items.type', MediaItemType::Music)
                ->whereNotNull('media_items.file_path')
                ->with(['musicMetadata', 'plays'])
                ->inRandomOrder()
                ->limit($limit)
                ->get();

        return response()->json([
            'items' => MediaItemResource::collection($items),
            'smart' => $request->boolean('smart'),
        ]);
    }

    /**
     * A frame per item, for the profile asking.
     *
     * Absent entries are normal and the client falls back to the poster:
     * nothing watched far enough in, the file on another machine, a format
     * that resists seeking. A broken image is worse than an ordinary one.
     *
     * Generated inline rather than queued because it is cheap -- measured at
     * 134ms for a 26KB JPEG -- and because a queued thumbnail would arrive
     * after the row had already rendered without it.
     *
     * @param  Collection<int, MediaItem>  $items
     * @return array<int, string>
     */
    private function resumeFrames($items): array
    {
        $profile = app(CurrentProfile::class)->get();

        if ($profile === null) {
            return [];
        }

        $frames = app(ResumeFrames::class);
        $out = [];

        // Cached frames first, for every item. These cost a file_exists each,
        // so the common case -- a shelf the viewer has already loaded once --
        // does no work at all.
        $uncached = [];

        foreach ($items as $item) {
            $position = (int) ($item->resume_position ?? 0);

            if ($position <= 0) {
                continue;
            }

            if ($url = $frames->cachedUrlFor($item, $position, $profile->id)) {
                $out[$item->id] = $url;

                continue;
            }

            $uncached[] = [$item, $position];
        }

        // Then a bounded number of extractions. Each is one synchronous ffmpeg
        // call -- measured at 134ms on this machine, but a slow or spun-down
        // disk is far worse -- and a cold shelf of twenty cards would other-
        // wise run twenty of them before the home page could render at all.
        //
        // The rest simply come back without a frame and the client shows the
        // poster, which is the same fallback every other absence uses. The
        // next load finds these cached and fills in a few more, so the shelf
        // converges over a couple of visits instead of stalling once.
        foreach (array_slice($uncached, 0, self::FRAMES_PER_REQUEST) as [$item, $position]) {
            if ($url = $frames->urlFor($item, $position, $profile->id)) {
                $out[$item->id] = $url;
            }
        }

        return $out;
    }

    /**
     * Everything this profile may see.
     */
    public function index(Request $request): JsonResponse
    {
        $items = $this->visible()->get();

        // Weak ETag: the payload is generated, so byte-equality is not
        // guaranteed across runs, but "nothing changed since" is exactly the
        // question the client is asking.
        $etag = 'W/"'.$this->fingerprint($items).'"';

        if (trim((string) $request->header('If-None-Match')) === $etag) {
            return response()->json(null, 304)->header('ETag', $etag);
        }

        return response()
            ->json([
                'items' => MediaItemResource::collection($items),
                // The client sends this back as ?since= next time, so the
                // server decides what "now" means rather than trusting a
                // device clock that may be wrong or in another timezone.
                'synced_at' => now()->toIso8601String(),
                'count' => $items->count(),
            ])
            ->header('ETag', $etag);
    }

    /**
     * What changed since a previous sync.
     *
     * Deletions are reported separately: a row that is simply absent from an
     * updates list is indistinguishable from one that never matched the
     * filter, and a device cannot tell "deleted" from "you never had it".
     */
    public function delta(Request $request): JsonResponse
    {
        $data = $request->validate([
            'since' => ['required', 'date'],
        ]);

        $since = Carbon::parse($data['since']);

        $updated = $this->visible()
            ->where('media_items.updated_at', '>', $since)
            ->get();

        return response()->json([
            'items' => MediaItemResource::collection($updated),
            // Ids the device should drop. Anything it holds that is no longer
            // visible — deleted, or now blocked by a rating cap — belongs in
            // this list, so the two cases are handled identically and neither
            // can linger on the device.
            'removed_ids' => $this->removedIds($request),
            'synced_at' => now()->toIso8601String(),
            'count' => $updated->count(),
        ]);
    }

    /**
     * The single place the gate is applied.
     *
     * Every endpoint reads through this. A second query built by hand
     * somewhere else is exactly how a bulk export starts leaking.
     */
    private function visible(): Builder
    {
        return $this->gate()
            ->apply(MediaItem::query())
            // `plays` too: the resource reports when this profile last played
            // an item, and reading that from an unloaded relation would be a
            // query per row — or, worse, silently null on every one (S-391).
            ->with(['musicMetadata', 'movieMetadata', 'showMetadata', 'bookMetadata', 'plays', 'parent:id,title,cover_image_url', 'probe'])
            ->orderBy('media_items.id');
    }

    /**
     * Ids the device is holding that it should not.
     *
     * The client sends what it has; anything not in the visible set comes
     * back. That covers deletion and a rating cap tightening with one
     * mechanism, rather than the server trying to remember what each device
     * was sent.
     *
     * @return array<int, int>
     */
    private function removedIds(Request $request): array
    {
        $held = collect($request->input('known_ids', []))
            ->filter(fn ($id) => is_numeric($id))
            ->map(fn ($id) => (int) $id);

        if ($held->isEmpty()) {
            return [];
        }

        $visible = $this->visible()
            ->whereIn('media_items.id', $held)
            ->pluck('media_items.id');

        return $held->diff($visible)->values()->all();
    }

    /**
     * Cheap change detector: how many rows and the newest timestamp.
     *
     * Not a hash of the payload — that would mean building the whole thing to
     * decide whether to send it, which defeats the point of a 304.
     */
    private function fingerprint(Collection $items): string
    {
        return $items->count().'-'.($items->max('updated_at')?->timestamp ?? 0)
            .'-'.($this->profiles()->id() ?? 0);
    }
}
