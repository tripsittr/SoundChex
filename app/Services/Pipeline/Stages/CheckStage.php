<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Services\Pipeline\Stages;

use App\Jobs\Pipeline\StageOutcome;
use App\Models\MediaItem;
use App\Services\Pipeline\Stage;

/**
 * Checks the file is actually any good (#465, filled out by #467).
 *
 * A placeholder that passes through. Phase 4 adds the real checks -- truncated
 * files, CAM rips, fake lossless, decode errors, upsampled audio -- and the
 * quality profile they rank against. Nothing checks quality today, which is why
 * a truncated file and a transcoded "FLAC" import as healthy.
 *
 * Here now so the sequence is complete and #467 is a handler swap rather than a
 * pipeline change.
 */
class CheckStage implements Stage
{
    public function run(MediaItem $item): StageOutcome
    {
        return StageOutcome::skipped('quality checks arrive with #467');
    }
}
