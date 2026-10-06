<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Services\Pipeline\Stages;

use App\Enums\MatchConfidence;
use App\Enums\MediaItemType;
use App\Events\CoverEmbedded;
use App\Jobs\Pipeline\StageOutcome;
use App\Models\MediaItem;
use App\Services\Metadata\CoverEmbedder;
use App\Services\Metadata\PostIdentificationTidying;
use App\Services\Pipeline\Stage;
use App\Services\WatchProviders;
use Illuminate\Support\Facades\Log;

/**
 * Fills in everything that follows from a chosen identity (#465).
 *
 * The tidying half of the old one-job design: credits, title cleanup, album
 * normalisation, cover embedding and streaming availability. These were five
 * sequential calls inside `EnrichMediaItemJob::handle()`, each wrapped in its
 * own try/catch, and a failure in any of them was invisible except as a log
 * line.
 *
 * They stay non-fatal here, because none of them is a reason to withhold a file
 * from the library -- but the stage now says so, and a failure that matters
 * ends up on the item rather than only in a log nobody reads.
 */
class EnrichStage implements Stage
{
    public function __construct(
        private PostIdentificationTidying $tidying,
        private CoverEmbedder $covers,
        private WatchProviders $availability,
    ) {}

    public function run(MediaItem $item): StageOutcome
    {
        $problems = [];

        foreach ($this->steps() as $name => $step) {
            try {
                $step($item);
                $item->refresh();
            } catch (\Throwable $e) {
                report($e);

                // Collected rather than thrown: one failing step must not
                // discard the four that worked.
                $problems[] = $name;

                Log::warning('An enrichment step failed', [
                    'item' => $item->id,
                    'step' => $name,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // A music track with no album needs a person: enrichment cannot tell
        // "a single that never had one" from "an album tag we failed to read",
        // and calling both complete is what left 83 tracks unflagged until a
        // client grouped them under "Unknown album" (S-384).
        //
        // Parked with a reason rather than only status-flagged, which is the
        // difference the pipeline makes to this case.
        if ($this->tidying->needsAlbumReview($item)) {
            return StageOutcome::needsReview('no album: is this a single, or did the album tag not read?');
        }

        if ($problems !== []) {
            // Skipped, not failed: the identity and its fields are saved, and
            // the file belongs in the library. The named steps are what did
            // not happen.
            return StageOutcome::skipped('these steps failed: '.implode(', ', $problems));
        }

        return StageOutcome::done();
    }

    /**
     * The steps, in the order the old job ran them.
     *
     * Credits before the title tidier because the tidier reads the artist the
     * sources settled on; the cover last because it rewrites the file.
     *
     * The first four come from `PostIdentificationTidying`, which is the old
     * job's own code lifted out rather than reimplemented -- each of those
     * methods prevented something specific and the reasoning lives with them.
     *
     * @return array<string, callable(MediaItem): void>
     */
    private function steps(): array
    {
        return [
            'credits' => fn (MediaItem $item) => $this->tidying->writeCredits($item),
            'title' => fn (MediaItem $item) => $this->tidying->tidyTitle($item),
            'album' => fn (MediaItem $item) => $this->tidying->normalizeAlbum($item),
            'cover' => function (MediaItem $item): void {
                // Only an exact match earns a rewrite of the user's file, and
                // only when the cover is not already in it (#463).
                if ($item->type !== MediaItemType::Music
                    || $item->match_confidence !== MatchConfidence::Exact) {
                    return;
                }

                if ($this->covers->isAvailable() && $this->covers->embed($item)) {
                    CoverEmbedded::dispatch($item);
                }
            },
            'availability' => fn (MediaItem $item) => $this->availability->refresh($item),
        ];
    }
}
