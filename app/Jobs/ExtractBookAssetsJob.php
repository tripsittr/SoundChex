<?php

namespace App\Jobs;

use App\Models\MediaItem;
use App\Services\Books\BookSearch;
use App\Services\Books\PdfAssetExtractor;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

/**
 * Recovers everything a book file carries beyond its raw bytes: illustrations,
 * the publisher's outline, and the text of every page for search.
 *
 * All local work on a file the user already has — no account, no network.
 */
class ExtractBookAssetsJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 900;

    public function __construct(public int $mediaItemId) {}

    public function middleware(): array
    {
        return [
            (new WithoutOverlapping("book-assets:{$this->mediaItemId}"))->dontRelease(),
        ];
    }

    public function handle(PdfAssetExtractor $extractor, BookSearch $search): void
    {
        $item = MediaItem::find($this->mediaItemId);

        if ($item === null) {
            return;
        }

        // Only PDFs: an EPUB's images and outline live in its own zip
        // structure, which epub.js already surfaces in the reader.
        if (! str_ends_with(strtolower((string) $item->file_path), '.pdf')) {
            return;
        }

        $extractor->extract($item);

        // Indexed after extraction so a search hit can be shown alongside the
        // chapter it falls in.
        $search->index($item);
    }
}
