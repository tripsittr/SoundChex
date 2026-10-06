<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Services\Pipeline\Stages;

use App\Jobs\Pipeline\StageOutcome;
use App\Models\MediaItem;
use App\Services\Pipeline\Stage;
use App\Services\Quality\QualityChecker;

/**
 * Checks the file is actually any good (#489).
 *
 * Was a pass-through placeholder (#489). Nothing checked quality at all before
 * this: a truncated file, a CAM rip and a file with no audio stream all
 * imported as healthy, and the owner found out when they pressed play.
 *
 * Only the cheap checks run here -- everything answerable from the probe and
 * the filename. The expensive ones (decoding the whole file for errors,
 * reading the spectrum to catch a lossy file in a lossless container) belong
 * in a background job that may finish after the item is published, because a
 * good file should not wait on them.
 */
class CheckStage implements Stage
{
    public function __construct(private QualityChecker $checker) {}

    public function run(MediaItem $item): StageOutcome
    {
        $findings = $this->checker->check($item);

        if ($findings === []) {
            return StageOutcome::done();
        }

        $blocking = array_values(array_filter(
            $findings,
            fn ($finding): bool => $finding->isBlocking(),
        ));

        if ($blocking === []) {
            // Warnings are recorded and do not stop anything. A library full
            // of blocked files nobody asked about is how a quality check gets
            // switched off.
            return StageOutcome::skipped(count($findings).' quality note(s) recorded');
        }

        // Named, because "quality problem" tells a person nothing and the
        // whole point of a review item is that it says what to do.
        $reasons = array_map(
            fn ($finding): string => str_replace('_', ' ', (string) $finding->check)
                .' ('.$finding->value.' vs '.$finding->threshold.')',
            $blocking,
        );

        return StageOutcome::needsReview(implode('; ', $reasons));
    }
}
