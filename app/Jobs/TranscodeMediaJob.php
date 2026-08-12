<?php

namespace App\Jobs;

use App\Models\MediaItem;
use App\Services\MediaTranscoder;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Converts one item to a browser-playable copy.
 *
 * Encoding a feature film takes minutes to hours, so it never runs in a
 * request. Progress is written to the item as it goes, which is the only
 * signal the UI has that a long encode is alive.
 */
class TranscodeMediaJob implements ShouldQueue
{
    use Queueable;

    /**
     * Encoding is expensive and rarely fails transiently — a retry usually
     * burns another hour reaching the same result.
     */
    public int $tries = 1;

    public function __construct(public readonly int $mediaItemId) {}

    /**
     * Must exceed the ffmpeg timeout, or the worker kills the job mid-encode
     * and leaves the item stuck as "running".
     */
    public function timeout(): int
    {
        return (int) config('transcode.timeout_seconds', 21600) + 300;
    }

    public function handle(MediaTranscoder $transcoder): void
    {
        $item = MediaItem::findOrFail($this->mediaItemId);

        if (! $transcoder->isAvailable()) {
            $item->forceFill(['transcode_status' => 'failed'])->saveQuietly();

            return;
        }

        $item->forceFill([
            'transcode_status' => 'running',
            'transcode_percent' => 0,
        ])->saveQuietly();

        // Throttled: ffmpeg reports often, and a write per line would hammer
        // SQLite for a number nobody reads that precisely.
        $lastWrite = 0;

        $path = $transcoder->convert($item, function (int $percent) use ($item, &$lastWrite): void {
            if ($percent - $lastWrite < 5) {
                return;
            }

            $lastWrite = $percent;

            $item->forceFill(['transcode_percent' => $percent])->saveQuietly();
        });

        if ($path === null) {
            $item->forceFill(['transcode_status' => 'failed'])->saveQuietly();

            return;
        }

        $item->forceFill([
            'converted_path' => $path,
            'transcode_status' => 'complete',
            'transcode_percent' => 100,
        ])->saveQuietly();
    }

    public function failed(\Throwable $exception): void
    {
        MediaItem::where('id', $this->mediaItemId)
            ->update(['transcode_status' => 'failed']);
    }
}
