<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Services\Pipeline\Stages;

use App\Jobs\Pipeline\StageOutcome;
use App\Models\MediaItem;
use App\Services\Pipeline\Stage;
use App\Services\Quality\MediaProber;

/**
 * Reads what the file actually contains (#467).
 *
 * Was a pass-through placeholder when the pipeline landed (#465). Now it runs
 * ffprobe and stores the result, which is what the quality stage and video
 * keep-best both need -- before this, no video technical data was stored
 * anywhere and keep-best compared file sizes.
 *
 * Idempotent: `probe()` replaces the row rather than adding one, so a re-run
 * after a remux measures the bytes as they are now.
 */
class ProbeStage implements Stage
{
    public function __construct(private MediaProber $prober) {}

    public function run(MediaItem $item): StageOutcome
    {
        if (! $this->prober->isAvailable()) {
            // A missing binary is not a bad file. Skipped with a reason, so an
            // item's history does not claim a probe that never happened.
            return StageOutcome::skipped('ffprobe is not available');
        }

        $probe = $this->prober->probe($item);

        if ($probe === null) {
            // ffprobe refused the file. For a book that is expected -- an EPUB
            // is not media -- so it is only a review item for audio and video,
            // where it means the file is unreadable or not what it claims.
            return $item->type === \App\Enums\MediaItemType::Book
                ? StageOutcome::skipped('books carry no streams to probe')
                : StageOutcome::needsReview('ffprobe could not read this file, so it may be corrupt or not media at all');
        }

        return StageOutcome::done();
    }
}
