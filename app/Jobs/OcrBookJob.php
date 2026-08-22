<?php

namespace App\Jobs;

use App\Models\MediaItem;
use App\Models\PageText;
use App\Services\OcrService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

/**
 * Recognises every scanned page in a book.
 *
 * Runs page by page rather than as one long batch so progress is visible and a
 * failure on one page doesn't lose the work already done. Pages that already
 * carry embedded text are skipped, which on a mostly-digital book means the
 * pass finishes almost immediately.
 */
class OcrBookJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    /** A long scanned book is genuinely slow; roughly a second a page. */
    public int $timeout = 7200;

    public function __construct(
        public int $mediaItemId,
        public bool $force = false,
    ) {}

    public function middleware(): array
    {
        return [
            (new WithoutOverlapping("ocr-book:{$this->mediaItemId}"))->dontRelease(),
        ];
    }

    public function handle(OcrService $ocr): void
    {
        $item = MediaItem::find($this->mediaItemId);

        if ($item === null) {
            return;
        }

        if (! $ocr->isAvailable()) {
            $item->forceFill(['ocr_status' => 'failed'])->saveQuietly();

            return;
        }

        $path = $item->absoluteFilePath();

        if ($path === null || ! is_file($path)) {
            $item->forceFill(['ocr_status' => 'failed'])->saveQuietly();

            return;
        }

        $total = $ocr->pageCount($path);

        if ($total === null || $total < 1) {
            $item->forceFill(['ocr_status' => 'failed'])->saveQuietly();

            return;
        }

        $item->forceFill([
            'ocr_status' => 'running',
            'ocr_percent' => 0,
            'page_count' => $total,
        ])->saveQuietly();

        $scanned = 0;

        for ($page = 1; $page <= $total; $page++) {
            $row = $ocr->recognizePage($item, $page, $this->force);

            // A blank page was read successfully; it just had nothing on it.
            // Counting only 'complete' would report a book of mostly-blank
            // plates as a failed pass.
            if (in_array($row?->status, ['complete', 'blank'], true)) {
                $scanned++;
            }

            // Written every few pages rather than every page: this is polled
            // by the admin table, and a write per page on a 500-page book is
            // a lot of churn for a number that moves by a fraction of a
            // percent each time.
            if ($page % 5 === 0 || $page === $total) {
                $item->forceFill([
                    'ocr_percent' => (int) round(($page / $total) * 100),
                    'scanned_page_count' => $scanned,
                ])->saveQuietly();
            }
        }

        $failed = PageText::where('media_item_id', $item->id)
            ->where('status', 'failed')
            ->count();

        $item->forceFill([
            'ocr_status' => $failed > 0 && $scanned === 0 ? 'failed' : 'complete',
            'ocr_percent' => 100,
            'scanned_page_count' => $scanned,
        ])->saveQuietly();
    }
}
