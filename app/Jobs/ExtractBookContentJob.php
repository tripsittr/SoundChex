<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Jobs;

use App\Models\MediaItem;
use App\Services\Books\BookTextExtractor;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Extracts a book to reflowable text in the background (S-295).
 *
 * Queued rather than run in the request: a scanned book means OCR-ing every page,
 * which is minutes of work. The reader asks for the content, this runs, and the
 * reader picks it up on its next poll. `ShouldBeUnique` would be ideal but the
 * base queue may be sync in some installs; the extractor is idempotent, so a
 * double-run only redoes the work, it does not duplicate rows.
 */
class ExtractBookContentJob implements ShouldQueue
{
    use Queueable;

    /** OCR of a long scanned book is slow; give it room. */
    public int $timeout = 1800;

    public function __construct(public readonly int $mediaItemId) {}

    public function handle(BookTextExtractor $extractor): void
    {
        $item = MediaItem::find($this->mediaItemId);

        if ($item === null) {
            return;
        }

        try {
            $count = $extractor->extract($item);
            Log::info('Extracted book content', ['item' => $item->id, 'units' => $count]);
        } catch (\Throwable $e) {
            Log::warning('Book content extraction failed', [
                'item' => $item->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
