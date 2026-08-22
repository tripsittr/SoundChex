<?php

namespace App\Jobs;

use App\Models\MediaItem;
use App\Services\Subtitles\SubtitleImporter;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

/**
 * Pulls in the caption tracks a video already carries.
 *
 * Local only — embedded streams and sidecar files. Online search is never
 * automatic: it needs an account and is rate-limited, so it stays a deliberate
 * action.
 */
class ImportSubtitlesJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 600;

    public function __construct(public int $mediaItemId) {}

    public function middleware(): array
    {
        return [
            (new WithoutOverlapping("subtitles:{$this->mediaItemId}"))->dontRelease(),
        ];
    }

    public function handle(SubtitleImporter $importer): void
    {
        if (! $importer->isAvailable()) {
            return;
        }

        $item = MediaItem::find($this->mediaItemId);

        if ($item === null) {
            return;
        }

        $importer->importAll($item);
    }
}
