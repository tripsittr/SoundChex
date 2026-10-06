<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Services\Pipeline\Stages;

use App\Jobs\Pipeline\StageOutcome;
use App\Models\MediaItem;
use App\Services\DuplicateDetector;
use App\Services\Pipeline\Stage;

/**
 * Compares the item against the library, now that it has been identified
 * (#465).
 *
 * This is the ordering fix. Duplicate detection used to run inside the scan
 * loop, *before* any metadata existed, so the ISRC, MusicBrainz, AcoustID,
 * TMDB and episode passes had nothing to compare and only byte-identical copies
 * were ever found at import -- which is the minority of real duplication. A
 * second rip of a film never matches byte for byte.
 *
 * Running it here means every pass has the identity it needs.
 */
class DedupeStage implements Stage
{
    public function __construct(private DuplicateDetector $detector) {}

    public function run(MediaItem $item): StageOutcome
    {
        $original = $this->detector->check($item);

        if ($original === null) {
            return StageOutcome::done();
        }

        // Flagged against something. Whether that is a duplicate to resolve or
        // a *version* to keep is #457's question, and until the version model
        // lands the honest answer is to ask rather than to act -- which is also
        // what duplicate_action defaults to.
        //
        // Parked, so the item does not go on to be filed while its relationship
        // to an existing copy is undecided.
        return StageOutcome::needsReview(
            'looks like a copy of #'.$original->id.' ('.($item->duplicate_match?->getLabel() ?? 'unknown match').')'
        );
    }
}
