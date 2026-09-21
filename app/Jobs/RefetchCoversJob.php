<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Jobs;

use App\Events\CoverFetched;
use App\Models\MediaItem;
use App\Services\DuplicateDetector;
use App\Services\Metadata\CoverArtFetcher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Refetches verified album covers for a set of tracks, in the background.
 *
 * Bulk cover refetch cannot run in the web request: it makes a network call per
 * album and throttles between them, so a few dozen rows blow past the request
 * timeout. This does the same work on the queue instead — the review screen just
 * dispatches it and the covers update as it runs.
 *
 * Efficient by the fetcher's design: one lookup per album, shared across an
 * album's tracks, de-duplicated downloads. The flag is cleared either way, so a
 * track with no confident match simply leaves the review list with its existing
 * cover.
 */
class RefetchCoversJob implements ShouldQueue
{
    use Queueable;

    /** Network-bound; a long list of albums is minutes, not seconds. */
    public int $timeout = 1800;

    public int $tries = 1;

    /**
     * @param  array<int, int>  $itemIds  The flagged media item ids to refetch.
     */
    public function __construct(public array $itemIds) {}

    public function handle(CoverArtFetcher $fetcher, DuplicateDetector $detector): void
    {
        MediaItem::query()
            ->whereIn('id', $this->itemIds)
            ->where('needs_cover_review', true)
            ->with('musicMetadata')
            ->chunkById(200, function ($items) use ($fetcher, $detector): void {
                foreach ($items as $item) {
                    $cover = $fetcher->fetchForAlbum(
                        $item->musicMetadata?->artist,
                        $item->musicMetadata?->album,
                    );

                    if ($cover !== null) {
                        $item->forceFill(['cover_image_url' => $cover])->saveQuietly();

                        // A verified cover was found and stored for this item —
                        // the hook a plugin that mirrors artwork elsewhere wants
                        // (S-285).
                        CoverFetched::dispatch($item, $cover);
                    }

                    // Cleared whether or not a cover was found — the row has been
                    // dealt with and should leave the review list.
                    $detector->clearCoverReview($item);
                }
            });
    }
}
