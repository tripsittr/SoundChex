<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Models;

use App\Jobs\SendWebhookNotificationJob;
use App\Services\WebhookNotifier;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Something worth telling someone about.
 *
 * Written when it happens and collected by each device when it next asks. One
 * row per event rather than per recipient: every profile that may see the item
 * sees the same event, and fanning out at write time would mean rewriting
 * history whenever a profile's permissions changed.
 */
class Notification extends Model
{
    /** New episode of a series already in the library. */
    public const EPISODE_ADDED = 'episode_added';

    /** A scan finished, summarised — never one per imported file. */
    public const SCAN_FINISHED = 'scan_finished';

    /** A download failed on a device. Written by the client. */
    public const DOWNLOAD_FAILED = 'download_failed';

    protected $fillable = [
        'type',
        'title',
        'body',
        'media_item_id',
    ];

    public function mediaItem(): BelongsTo
    {
        return $this->belongsTo(MediaItem::class);
    }

    /**
     * Records an event, unless an identical one was just recorded.
     *
     * A scan that runs every few minutes over an unchanged folder would
     * otherwise write the same summary forever, and a series filing three
     * episodes in one pass should not produce three near-identical rows within
     * the same second.
     */
    public static function record(string $type, string $title, ?string $body = null, ?MediaItem $item = null): ?self
    {
        $duplicate = static::query()
            ->where('type', $type)
            ->where('title', $title)
            ->where('media_item_id', $item?->id)
            ->where('created_at', '>', now()->subMinutes(10))
            ->exists();

        if ($duplicate) {
            return null;
        }

        $notification = static::create([
            'type' => $type,
            'title' => $title,
            'body' => $body,
            'media_item_id' => $item?->id,
        ]);

        // Fan the same event out to any configured webhook (Discord, Slack, a
        // generic hook) on the queue, so a slow or dead endpoint never holds up
        // the scan or download that recorded this. No-op when none are set up.
        if (app(WebhookNotifier::class)->hasDestinations()) {
            SendWebhookNotificationJob::dispatch($notification->id);
        }

        return $notification;
    }

    /**
     * Drops what nobody will ever read.
     *
     * These are "what happened while you were away", not a permanent log. A
     * device that has not opened in a month does not want a month of history,
     * and an unbounded table on a server that scans every few minutes grows
     * without limit.
     */
    public static function prune(int $days = 30): int
    {
        return static::where('created_at', '<', now()->subDays($days))->delete();
    }
}
