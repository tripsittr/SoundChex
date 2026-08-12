<?php

namespace App\Jobs;

use App\Models\MediaItem;
use App\Services\OcrService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

/**
 * Recognises a single page.
 *
 * Dispatched when a reader opens a page that has no text of its own, so the
 * page becomes selectable a moment after it appears rather than only after a
 * whole-book pass.
 */
class OcrPageJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 180;

    public function __construct(
        public int $mediaItemId,
        public int $page,
        public bool $force = false,
    ) {}

    /**
     * Two readers on the same page would otherwise recognise it twice.
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping("ocr:{$this->mediaItemId}:{$this->page}"))
                ->releaseAfter(30)
                ->expireAfter(300),
        ];
    }

    public function handle(OcrService $ocr): void
    {
        if (! $ocr->isAvailable()) {
            return;
        }

        $item = MediaItem::find($this->mediaItemId);

        if ($item === null) {
            return;
        }

        $ocr->recognizePage($item, $this->page, $this->force);
    }
}
