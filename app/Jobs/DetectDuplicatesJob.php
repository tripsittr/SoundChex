<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Jobs;

use App\Enums\DuplicateStatus;
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
        // Read through ?? rather than directly: a job serialised before this
        // property existed unserialises without it, and a typed property with
        // no value throws on access rather than reading as null. The queue
        // survives an upgrade, so those payloads are still in the table.
        $type = $this->type ?? null;

        // Drop any stale flags that point at no original, so they leave the
        // review list and are judged afresh below rather than lingering.
        $detector->clearOrphans();

        MediaItem::unresolved()
            ->whereNotNull('file_path')
            ->when($type !== null, fn ($query) => $query->where('type', $type))
            // A file someone has reviewed is still searched. Their decision was
            // about a pair, and `duplicate_decisions` remembers it as one, so the
            // detector passes over that pair and still considers every other --
            // which is the point: a file kept last week was previously never
            // compared against anything added since.
            //
            // Merged is the exception, and stays excluded. Merging repoints the
            // redundant row at the surviving file, so such a row now shares a
            // path, and bytes, with its original. Searching it would find a third
            // copy and drag a settled row back into review for a file it does not
            // even have its own copy of.
            ->where(function ($query) {
                $query->whereNull('duplicate_status')
                    ->orWhereIn('duplicate_status', [
                        DuplicateStatus::Pending->value,
                        DuplicateStatus::Kept->value,
                    ]);
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
