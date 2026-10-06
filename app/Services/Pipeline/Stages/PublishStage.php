<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Services\Pipeline\Stages;

use App\Enums\ProcessingStatus;
use App\Events\MediaItemAdded;
use App\Events\MediaItemEnriched;
use App\Jobs\Pipeline\StageOutcome;
use App\Models\MediaItem;
use App\Services\Pipeline\Stage;

/**
 * Makes the item visible and says so (#489).
 *
 * The last stage, and the only one that sets `processing_status` to `complete`
 * -- which is what `ResolvedScope` uses to show an item at all. Everything
 * before this is invisible to clients on purpose: a half-identified item in the
 * library is worse than one that has not appeared yet.
 *
 * Both events fire here rather than mid-pipeline, because both mean "this item
 * has settled", and firing them from inside a stage that might still park the
 * item was how a plugin could see an item that then vanished from the library.
 */
class PublishStage implements Stage
{
    public function run(MediaItem $item): StageOutcome
    {
        // A person who has already judged this item keeps their verdict: a
        // re-run must not drag a reviewed item back into the queue, which is
        // the S-302 trap.
        $status = $item->reviewed_at !== null && $item->processing_status === ProcessingStatus::NeedsReview
            ? $item->processing_status
            : ProcessingStatus::Complete;

        $item->forceFill(['processing_status' => $status])->saveQuietly();

        MediaItemEnriched::dispatch($item);

        // The item has settled into the library as a real, kept item --
        // distinct from `media.catalogued`, which fires the moment the file is
        // first seen, before anything could reject or reshape it (S-285).
        MediaItemAdded::dispatch($item);

        return StageOutcome::done();
    }
}
