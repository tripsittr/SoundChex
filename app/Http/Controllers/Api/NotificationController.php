<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MediaItem;
use App\Models\Notification;
use App\Services\ContentGate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * Events the device has not seen yet.
 *
 * Pull rather than push. Reaching a phone in someone's pocket needs Apple's
 * service and its own infrastructure; this is what the app collects when it is
 * next opened, and it is also what a push implementation would send.
 *
 * Filtered through the same gate as the library. A notification names a title,
 * so an ungated list would tell a capped profile exactly what it is not allowed
 * to see.
 */
class NotificationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $since = $request->query('since');

        $query = Notification::query()
            ->latest('id')
            ->limit(50);

        if (filled($since)) {
            $query->where('id', '>', (int) $since);
        }

        $notifications = $query->get();

        // Anything about an item this profile may not see is dropped. Cheaper
        // than joining: most notifications carry no item at all, and the ones
        // that do are few enough to check in one query.
        $visible = $this->visibleItemIds($notifications->pluck('media_item_id')->filter());

        $items = $notifications
            ->filter(fn (Notification $n) => $n->media_item_id === null
                || $visible->contains($n->media_item_id))
            ->map(fn (Notification $n) => [
                'id' => $n->id,
                'type' => $n->type,
                'title' => $n->title,
                'body' => $n->body,
                'item_id' => $n->media_item_id,
                'created_at' => $n->created_at?->toIso8601String(),
            ])
            ->values();

        return response()->json([
            'items' => $items,
            // The highest id seen, so the next request can ask for what came
            // after it — including ids filtered out here, which must not be
            // offered again.
            'cursor' => $notifications->max('id'),
        ]);
    }

    private function visibleItemIds(Collection $ids): Collection
    {
        if ($ids->isEmpty()) {
            return collect();
        }

        return app(ContentGate::class)
            ->apply(MediaItem::query())
            ->whereIn('media_items.id', $ids->all())
            ->pluck('media_items.id');
    }
}
