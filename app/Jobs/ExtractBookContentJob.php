<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Jobs;

use App\Models\MediaItem;
use App\Services\Books\BookTextExtractor;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Extracts a scanned book to reflowable text in the background (S-295).
 *
 * Only for books that need OCR — text PDFs and EPUBs are extracted in the request
 * itself. OCR-ing every page of a scan is minutes of work, so it is queued and
 * the reader polls for the result.
 *
 * Unique per book: the reader polls while it waits, and without this each poll
 * would queue another copy (which is exactly what piled up 33 duplicate jobs the
 * first time). On its own `reader` queue so a book is not stuck behind a long
 * enrichment backlog on the default queue.
 */
class ExtractBookContentJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /** OCR of a long scanned book is slow; give it room. */
    public int $timeout = 1800;

    /** Drop a stale unique lock after this, so a failed run can be retried. */
    public int $uniqueFor = 3600;

    public function __construct(public readonly int $mediaItemId)
    {
        $this->onQueue('reader');
    }

    public function uniqueId(): string
    {
        return (string) $this->mediaItemId;
    }

    public function handle(BookTextExtractor $extractor): void
    {
        $item = MediaItem::unresolved()->find($this->mediaItemId);

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
