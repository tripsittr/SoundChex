<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Services\Pipeline\Stages;

use App\Enums\DuplicateStatus;
use App\Jobs\Pipeline\StageOutcome;
use App\Models\MediaItem;
use App\Services\DuplicateDetector;
use App\Services\Pipeline\Stage;
use App\Services\Versions\VersionGrouper;

/**
 * Compares the item against the library, now that it has been identified
 * (#489), and decides whether a match is a duplicate or a version (#489).
 *
 * Two fixes in one stage:
 *
 * **Ordering.** Duplicate detection used to run inside the scan loop, before
 * any metadata existed, so the ISRC, MusicBrainz, AcoustID, TMDB and episode
 * passes had nothing to compare -- only byte-identical copies were ever found
 * at import, which is the minority of real duplication. A second rip of a film
 * never matches byte for byte.
 *
 * **Verdict.** Every match used to park the item for review. Measured on this
 * library, ~29% of those were the same recording on a *different release* --
 * the album cut and the greatest-hits copy -- so the owner was being asked to
 * adjudicate pairs that both belonged. Under the user's rule ("if Spotify has
 * 15 versions, we should too") those are versions, and a version is
 * information rather than a question.
 */
class DedupeStage implements Stage
{
    public function __construct(
        private DuplicateDetector $detector,
        private VersionGrouper $grouper,
    ) {}

    public function run(MediaItem $item): StageOutcome
    {
        // The work and edition keys first: the verdict below is meaningless
        // without them, and this is the first point in the pipeline where
        // identification has settled enough to derive them.
        $this->grouper->classify($item);

        $item = $item->fresh(['musicMetadata', 'movieMetadata', 'showMetadata', 'bookMetadata', 'probe']);

        $original = $this->detector->check($item);

        if ($original === null) {
            // Nothing matched. Still elect a primary, because a work with one
            // copy is its own primary and leaving the column unset would make
            // it mean "unknown" rather than "yes".
            $this->grouper->electPrimary($item);

            return StageOutcome::done();
        }

        $verdict = $this->grouper->compare($item, $original->loadMissing(['musicMetadata', 'movieMetadata', 'probe']));

        if (! $verdict->needsReview()) {
            // A version, a quality variant, or a pair that only looked alike.
            // All of them keep both files, so the flag the detector just set
            // has to come off -- leaving it would put a version in the
            // duplicate queue, which is the behaviour being fixed.
            $this->clearDuplicateFlag($item);
            $this->grouper->electPrimary($item->fresh());

            return StageOutcome::skipped($verdict->value.': '.$verdict->explanation());
        }

        // A real duplicate, or two copies of identical bytes. Parked rather
        // than resolved: which copy to keep depends on things the code cannot
        // see, and `duplicate_action` defaults to asking.
        return StageOutcome::needsReview(
            $verdict->label().' of #'.$original->id.'. '.$verdict->explanation()
        );
    }

    /**
     * Un-flags a pair the version model says is not a duplicate.
     *
     * `DuplicateDetector::check()` flags before this stage can judge, because
     * it is also what finds the candidate. Clearing the flag here is how the
     * two stay consistent -- the alternative is teaching the detector about
     * editions, which would duplicate the version model inside it.
     */
    private function clearDuplicateFlag(MediaItem $item): void
    {
        if ($item->duplicate_status !== DuplicateStatus::Pending) {
            return;
        }

        $item->forceFill([
            'duplicate_of_id' => null,
            'duplicate_status' => null,
            'duplicate_match' => null,
            'duplicate_detected_at' => null,
        ])->saveQuietly();
    }
}
