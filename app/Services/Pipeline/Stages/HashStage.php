<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Services\Pipeline\Stages;

use App\Jobs\Pipeline\StageOutcome;
use App\Models\MediaItem;
use App\Services\DuplicateDetector;
use App\Services\Pipeline\Stage;

/**
 * Writes the file's content hash (#489).
 *
 * Its own stage, on the `io` queue, because hashing is the slowest cheap thing
 * the pipeline does -- a 40 GB remux takes real time -- and it used to happen
 * inside the scan loop, where it held up cataloguing everything behind it.
 *
 * A file too large for the configured limit simply has no hash. That is a
 * supported outcome, not a failure: it only means the file never takes part in
 * byte-identical duplicate detection.
 */
class HashStage implements Stage
{
    public function __construct(private DuplicateDetector $detector) {}

    public function run(MediaItem $item): StageOutcome
    {
        // Idempotent by construction: ensureHashed() returns the stored hash
        // when there is one and never rehashes.
        $hash = $this->detector->ensureHashed($item);

        if ($hash === null) {
            return StageOutcome::skipped('not hashed: unreadable or over the size limit');
        }

        return StageOutcome::done();
    }
}
