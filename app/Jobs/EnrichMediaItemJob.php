<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Jobs;

use App\Enums\MatchConfidence;
use App\Enums\MediaItemType;
use App\Enums\ProcessingStatus;
use App\Events\MediaItemEnriched;
use App\Models\MediaItem;
use App\Models\MetadataVersion;
use App\Plugins\Registry;
use App\Services\LibraryOrganizer;
use App\Services\Metadata\CoverEmbedder;
use App\Services\Metadata\MetadataPipeline;
use App\Services\MetadataHistory;
use App\Services\MusicCredits;
use App\Services\TitleTidier;
use App\Services\WatchProviders;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

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

            $this->writeCredits($item);
            $this->tidyTitle($item);
            $this->embedCover($item);
            $this->refreshAvailability($item);
            $this->fileIntoLibrary($item, $organizer);

            // Enrichment is done. Plugins subscribed to this react to a freshly
            // enriched item — the point a scrobbler or a derived-data plugin
            // hooks (S-264 Phase 3).
            MediaItemEnriched::dispatch($item);
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
    /**
     * Records who is credited on a track.
     *
     * After the pipeline, so it reads whatever artist the sources settled on
     * rather than the filename's guess. Music only — books and film write
     * their own credits from their own sources.
     *
     * Non-fatal. Enrichment has already saved the metadata that matters, and
     * losing a credit is not worth re-running the whole pipeline for.
     */
    private function writeCredits(MediaItem $item): void
    {
        if ($item->type !== MediaItemType::Music) {
            return;
        }

        try {
            $item->refresh()->load('musicMetadata');

            $artist = $item->musicMetadata?->artist;

            if (blank($artist)) {
                return;
            }

            $credits = app(MusicCredits::class);

            // MusicBrainz writes credits itself when it matches a recording,
            // with each artist's stable MBID (S-38). Those are the better record,
            // so only parse the joined artist string when it did not — otherwise
            // this would detach the id-carrying people and re-attach the same
            // names with no ids.
            if (! $this->hasIdentifiedCredits($item)) {
                $credits->fromCreditString($item, $artist);
            }

            // The column browsing groups on. Kept in step here rather than by
            // a separate pass, so a track uploaded today is grouped correctly
            // the moment it is catalogued.
            $primary = $credits->primaryFor($artist);

            if ($primary !== null && $item->musicMetadata?->primary_artist !== $primary) {
                $item->musicMetadata->forceFill(['primary_artist' => $primary])->saveQuietly();
            }
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /**
     * Whether the item already carries credits with a MusicBrainz id — i.e.
     * MusicBrainz matched and wrote them. That is the record not to overwrite by
     * re-parsing the joined artist string.
     */
    private function hasIdentifiedCredits(MediaItem $item): bool
    {
        return $item->people()
            ->wherePivotIn('role', [MusicCredits::PRIMARY, MusicCredits::FEATURED])
            ->whereNotNull('musicbrainz_artist_id')
            ->exists();
    }

    /**
     * Last look at the title before the file is named after it.
     *
     * Every source has had its say by now, and any of them can leave a title
     * holding its own artist — a tag written that way, a filename read back as
     * a title, a provider returning "Song - Artist" as the track name. The
     * promotion in FileTagger only fires when a tag disagrees, so a file whose
     * tag *is* "Gold - Imagine Dragons" keeps it.
     *
     * Checked here because it is the last step before filing, and filing names
     * the file after the title: left until afterwards, the bad name is already
     * on disk and the scanner will read it back as a title next time round.
     *
     * Deliberately narrow, the same way the other two guards are: only this
     * track's own artist, only at the end, and never to an empty title.
     */
    private function tidyTitle(MediaItem $item): void
    {
        try {
            $item->refresh();

            $original = (string) $item->title;
            $title = $original;

            // Music: strip this track's own artist out of its title. Music-only,
            // because only a track carries its artist in the title this way.
            if ($item->type === MediaItemType::Music) {
                $item->load('musicMetadata');

                $title = app(TitleTidier::class)->strip($title, [
                    $item->musicMetadata?->artist,
                    $item->musicMetadata?->primary_artist,
                ]) ?? $title;
            }

            // Then let a plugin have the last word on the final title, for every
            // media type (S-264 Phase 3). The filter receives the title and the
            // item and returns the title to keep — a no-op when none is
            // registered, so this changes nothing for a plugin-less install.
            $title = (string) app(Registry::class)->apply('metadata.title', $title, $item);

            if ($title === '' || $title === $original) {
                return;
            }

            Log::info('Adjusted a title', [
                'item' => $item->id,
                'was' => $original,
                'now' => $title,
            ]);

            $item->forceFill(['title' => $title])->saveQuietly();
        } catch (\Throwable $e) {
            // The metadata is already saved; a clumsy title is not worth
            // failing the run and re-fetching everything.
            report($e);
        }
    }

    /**
     * Bakes the resolved cover into the audio file (S-274).
     *
     * Only for a track we are confident about — a Fuzzy/None match may still
     * carry the wrong cover, and we do not want to write that into the file.
     * Music only, and non-fatal: the metadata is already saved, and a file we
     * can't rewrite (permissions, a read-only drive, no ffmpeg) is no reason to
     * fail the run. The embedder itself writes atomically and never touches the
     * original on failure.
     */
    private function embedCover(MediaItem $item): void
    {
        if ($item->type !== MediaItemType::Music
            || $item->match_confidence !== MatchConfidence::Exact) {
            return;
        }

        try {
            $embedder = app(CoverEmbedder::class);

            if ($embedder->isAvailable()) {
                $embedder->embed($item->refresh());
            }
        } catch (\Throwable $e) {
            report($e);
        }
    }

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
            // Which item, not just which exception. `report()` alone gives a
            // stack trace with nothing to act on — a full disk or an unplugged
            // drive fails every item in a scan the same way, and the question
            // afterwards is always "which files are still in the inbox?".
            Log::error('Could not file an item into the library', [
                'item' => $item->id,
                'title' => $item->title,
                'path' => $item->file_path,
                'error' => $e->getMessage(),
            ]);

            report($e);
        }
    }

    public function failed(\Throwable $exception): void
    {
        MediaItem::where('id', $this->mediaItemId)
            ->update(['processing_status' => ProcessingStatus::Failed->value]);
    }
}
