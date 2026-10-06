<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Services;

use App\Enums\IngestOrigin;
use App\Enums\MediaItemType;
use App\Enums\PipelineStage;
use App\Enums\PipelineState;
use App\Enums\ProcessingStatus;
use App\Events\MediaItemCatalogued;
use App\Models\MediaItem;
use App\Services\Pipeline\PipelineRunner;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The one way a file becomes a row (#465).
 *
 * Five import paths had grown their own versions of "catalogue this file": the
 * scanner, `library:import-music`, the two admin upload actions and the CSV
 * import. The scanner hashed the file, checked for duplicates, recorded intake
 * history, fired the catalogued event and queued artwork and subtitles; the
 * other four did some subset. So whether an item ever got artwork, or was ever
 * compared against the library, depended on which door it came through — which
 * is not a property anybody chose, and is why "uploaded files behave
 * differently" was true and hard to pin down.
 *
 * Everything goes through `accept()` now. The row is created and given its
 * first pipeline stage **in one transaction**, which closes the window where a
 * crash between the two left a row hidden forever: `ResolvedScope` hides
 * anything that is not `complete`, and with no stage the sweeper had nothing to
 * find.
 */
class LibraryIngest
{
    public function __construct(
        private PipelineRunner $runner,
        private MetadataHistory $history,
    ) {}

    /**
     * Takes responsibility for a file and returns its row.
     *
     * Returns null when the path is already catalogued — which is not an error:
     * every scan sees every file again, and the answer is "already handled".
     *
     * @param  array<string, mixed>  $metadata  Type-specific fields known up front.
     * @param  array<string, mixed>  $attributes  Extra columns for the item row.
     */
    public function accept(
        string $path,
        MediaItemType $type,
        string $title,
        IngestOrigin $origin,
        ?int $userId = null,
        array $metadata = [],
        array $attributes = [],
    ): ?MediaItem {
        if ($this->alreadyKnown($path)) {
            return null;
        }

        try {
            $item = DB::transaction(function () use ($path, $type, $title, $origin, $userId, $metadata, $attributes): MediaItem {
                $item = MediaItem::create([
                    'user_id' => $userId,
                    'type' => $type,
                    // The pipeline promotes the real title once identified.
                    'title' => $title,
                    'file_path' => $path,
                    // Captured once, here (S-119), so library-size totals are a
                    // stored SUM rather than a per-item filesize() sample.
                    'file_size' => $this->sizeOf($path),
                    'processing_status' => ProcessingStatus::Pending,
                    'owned' => true,
                ] + $attributes);

                // Sources write into this row rather than creating it, so it
                // has to exist before anything enriches.
                $item->metadata()->create($metadata);

                // In the same transaction as the row. A crash between creating
                // a row and giving it a stage left it hidden with nothing to
                // find it -- which is the bug, not an edge case.
                $item->forceFill([
                    'pipeline_stage' => PipelineStage::Catalogued,
                    'pipeline_state' => PipelineState::Queued,
                    'pipeline_attempts' => 0,
                    'pipeline_updated_at' => now(),
                    'enrichment_report' => ['ingest_origin' => $origin->value],
                ])->saveQuietly();

                return $item;
            });
        } catch (\Throwable $e) {
            // Named with the actual exception, because the alternative is a
            // scan that reports a count and silently drops a file -- and
            // because a swallowed constraint violation looks exactly like
            // "already catalogued" to the caller.
            Log::error('Could not catalogue a file', [
                'path' => $path,
                'origin' => $origin->value,
                'exception' => $e::class,
                'error' => $e->getMessage(),
            ]);

            // Rethrown for the paths that can act on it: a command should fail
            // loudly rather than report a successful import of nothing, and a
            // queued stage should be retried. The scanner catches it per file
            // so one bad file does not end the scan.
            throw new IngestFailed("Could not catalogue {$path}: {$e->getMessage()}", previous: $e);
        }

        $item = $item->fresh(['musicMetadata', 'movieMetadata', 'showMetadata', 'bookMetadata']);

        // The state the file arrived in — its original name, path, size and
        // tags — captured before anything renames or rewrites it (S-21).
        // Non-fatal: losing the intake record must not lose the file.
        try {
            $this->history->recordIntake($item);
        } catch (\Throwable $e) {
            report($e);
        }

        // Something arrived, before any enrichment ran (S-264 Phase 3).
        MediaItemCatalogued::dispatch($item);

        // And into the pipeline. Outside the transaction deliberately: a job
        // dispatched inside one can be picked up by a worker before the commit
        // lands and find no row.
        $this->runner->start($item);

        return $item;
    }

    /**
     * Whether this path is already catalogued.
     *
     * Unscoped, because a path already held by a hidden row — pending, failed,
     * awaiting review — is still taken, and a scoped check would catalogue the
     * same file a second time.
     */
    private function alreadyKnown(string $path): bool
    {
        return MediaItem::withoutGlobalScopes()->where('file_path', $path)->exists();
    }

    /**
     * The size in bytes, or null when it cannot be read.
     *
     * `$path` is stored as `file_path`: absolute for a watched folder outside
     * the app disk, relative for one inside it — the shape
     * `MediaItem::absoluteFilePath()` resolves.
     */
    private function sizeOf(string $path): ?int
    {
        $absolute = str_starts_with($path, DIRECTORY_SEPARATOR) || preg_match('#^[A-Za-z]:[\\\\/]#', $path) === 1
            ? $path
            : \Storage::path($path);

        $size = @filesize($absolute);

        return $size === false ? null : $size;
    }
}
