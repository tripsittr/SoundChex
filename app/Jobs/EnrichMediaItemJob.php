<?php

namespace App\Jobs;

use App\Enums\ProcessingStatus;
use App\Models\MediaItem;
use App\Models\MetadataVersion;
use App\Services\LibraryOrganizer;
use App\Services\MetadataHistory;
use App\Services\Metadata\MetadataPipeline;
use App\Services\WatchProviders;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class EnrichMediaItemJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;
    public int $backoff = 60;

    public function __construct(public readonly int $mediaItemId) {}

    public function handle(
        MetadataPipeline $pipeline,
        LibraryOrganizer $organizer,
        MetadataHistory $history,
    ): void {
        $item = MediaItem::with(['musicMetadata', 'movieMetadata', 'showMetadata', 'bookMetadata'])
            ->findOrFail($this->mediaItemId);

        $item->update(['processing_status' => ProcessingStatus::Processing]);

        // Taken before the pipeline runs, so whatever it overwrites is
        // recoverable. Providers revise their own records — a corrected title,
        // a re-dated release, an entry merged into another — and without this
        // the previous values are simply gone.
        $before = $history->snapshot($item);

        try {
            $pipeline->run($item);

            // A source may have flagged an ambiguous match mid-run. That verdict
            // outranks a blanket "complete" — don't bury it.
            $item->refresh();

            if ($item->processing_status !== ProcessingStatus::NeedsReview) {
                $item->update(['processing_status' => ProcessingStatus::Complete]);
            }

            $this->recordHistory($item, $history, $before);

            $this->refreshAvailability($item);
            $this->fileIntoLibrary($item, $organizer);
        } catch (\Throwable $e) {
            $item->update(['processing_status' => ProcessingStatus::Failed]);
            throw $e;
        }
    }

    /**
     * Records what this run changed.
     *
     * The pre-run snapshot is stored rather than the post-run one: a version
     * represents the state *before* the change it's attached to, which is what
     * makes "restore this version" mean going back to it.
     *
     * Non-fatal — the metadata is already saved, and losing one history entry
     * is not worth failing the job and re-running the whole pipeline.
     */
    private function recordHistory(MediaItem $item, MetadataHistory $history, array $before): void
    {
        try {
            $item->load(['musicMetadata', 'movieMetadata', 'showMetadata', 'bookMetadata', 'tags']);

            $after = $history->snapshot($item);
            $changed = $history->changedFields($before, $after);

            // A run that found the same data has nothing to record.
            if ($changed === []) {
                return;
            }

            MetadataVersion::create([
                'media_item_id' => $item->id,
                'snapshot' => $before,
                'reason' => MetadataVersion::REASON_ENRICHMENT,
                'source' => $item->matched_by,
                'changed_fields' => $changed,
            ]);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /**
     * Refreshes where this title can currently be streamed.
     *
     * Non-fatal like the organizer below: metadata is already saved, and a
     * TMDB outage or a missing key shouldn't fail the job and re-run the whole
     * pipeline. Availability simply stays as it was until the next pass.
     */
    private function refreshAvailability(MediaItem $item): void
    {
        try {
            app(WatchProviders::class)->refresh($item);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /**
     * Moves the file into the Artist/Album tree now that enrichment has
     * resolved real tags.
     *
     * Deliberately non-fatal: the metadata is already saved, so a file that
     * can't be moved (permissions, a full disk, a disconnected drive) should
     * not fail the job and re-run the whole pipeline. The item simply stays
     * where it is and `library:organize` can retry later.
     */
    private function fileIntoLibrary(MediaItem $item, LibraryOrganizer $organizer): void
    {
        if (! config('library.auto_organize', true)) {
            return;
        }

        try {
            $organizer->organize($item->refresh());
        } catch (\Throwable $e) {
            report($e);
        }
    }

    public function failed(\Throwable $exception): void
    {
        MediaItem::where('id', $this->mediaItemId)
            ->update(['processing_status' => ProcessingStatus::Failed->value]);
    }
}
