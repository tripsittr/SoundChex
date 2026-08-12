<?php

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

    public function handle(DuplicateDetector $detector): void
    {
        MediaItem::query()
            ->whereNotNull('file_path')
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
