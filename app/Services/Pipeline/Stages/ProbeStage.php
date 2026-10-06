<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Services\Pipeline\Stages;

use App\Jobs\Pipeline\StageOutcome;
use App\Models\MediaItem;
use App\Services\Pipeline\Stage;

/**
 * Reads technical facts from the file itself (#465, filled out by #467).
 *
 * A placeholder that passes through, on purpose. Phase 4 adds `media_probes`
 * and ffprobe for every type; until then there is no probe to run, and the
 * stage exists so the sequence is in place and items flow through the real
 * pipeline rather than waiting for a table that does not exist yet.
 *
 * Skipped rather than done, so an item's history does not claim a probe
 * happened.
 */
class ProbeStage implements Stage
{
    public function run(MediaItem $item): StageOutcome
    {
        return StageOutcome::skipped('probing arrives with #467');
    }
}
