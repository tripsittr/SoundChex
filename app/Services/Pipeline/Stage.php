<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Services\Pipeline;

use App\Jobs\Pipeline\StageOutcome;
use App\Models\MediaItem;

/**
 * One step of the library pipeline (#489).
 *
 * Each implementation must be **idempotent**: running it twice has to leave the
 * same state as running it once. That is what makes crash recovery simply
 * "run the stage that did not commit" rather than a special case per stage, and
 * the sweeper requeues freely on that basis.
 */
interface Stage
{
    /**
     * Does this stage's work and says what happened.
     *
     * Never returns null and never throws for an expected condition -- the
     * outcome type carries all five answers, which is the point of it.
     */
    public function run(MediaItem $item): StageOutcome;
}
