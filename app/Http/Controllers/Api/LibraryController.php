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
            ->with(['musicMetadata', 'movieMetadata', 'showMetadata', 'bookMetadata', 'plays'])
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
