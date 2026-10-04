<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Jobs;

use App\Models\MediaItem;
use App\Services\DuplicateDetector;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Hashes catalogued files and flags identical copies.
 *
 * Queued because hashing a whole library is far too slow for a web request —
 * a few thousand audio files is seconds, but video runs to gigabytes each.
 */
class DetectDuplicatesJob implements ShouldQueue
{
    use Queueable;

    /** Hashing is I/O-bound; a stalled disk shouldn't retry forever. */
    public int $tries = 1;

    public int $timeout = 3600;

    /**
     * @param  string|null  $type  Limit the sweep to one media type, or null for
     *                             the whole library. The buttons on a list page
     *                             scan what that page shows; the scheduled sweep
     *                             still covers everything.
     */
    public function __construct(public ?string $type = null) {}

    public function handle(DuplicateDetector $detector): void
    {
        // Drop any stale flags that point at no original, so they leave the
        // review list and are judged afresh below rather than lingering.
        $detector->clearOrphans();

        MediaItem::unresolved()
            ->whereNotNull('file_path')
            ->when($this->type !== null, fn ($query) => $query->where('type', $this->type))
            // Rows the user already decided on are left alone; re-flagging a
            // pair they chose to keep would refill the review list.
            ->where(function ($query) {
                $query->whereNull('duplicate_status')
                    ->orWhere('duplicate_status', 'pending');
            })
            ->orderBy('id')
            // Chunked so a large library doesn't load entirely into memory.
            ->chunkById(200, function ($items) use ($detector): void {
                foreach ($items as $item) {
                    $detector->check($item);
                }
            });
    }
}
