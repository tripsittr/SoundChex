<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Services\Pipeline\Stages;

use App\Jobs\Pipeline\StageOutcome;
use App\Models\MediaItem;
use App\Services\Pipeline\Stage;

/**
 * Confirms the row describes a file that is actually there (#489).
 *
 * `LibraryIngest` has already created the row, so this stage looks like it has
 * nothing to do -- and that is deliberate. It is the gate: a row whose file
 * cannot be read must not travel down a pipeline that will try to hash, probe,
 * tag and move it, failing at each stage in a different way. One clear answer
 * here beats six confusing ones later.
 *
 * It is also where a backfilled row re-enters, having been given this stage by
 * the migration without anything having checked it.
 */
class CatalogueStage implements Stage
{
    public function run(MediaItem $item): StageOutcome
    {
        $path = $item->absoluteFilePath();

        if ($path === null) {
            // No resolvable path at all -- a row from a CSV or a transfer whose
            // file never arrived.
            return StageOutcome::needsReview('no file path could be resolved for this item');
        }

        if (! is_file($path)) {
            return StageOutcome::needsReview("the file is not there: {$path}");
        }

        if (! is_readable($path)) {
            // Distinct from missing, because the fix is different: a
            // permissions problem, not a lost file.
            return StageOutcome::needsReview("the file cannot be read: {$path}");
        }

        // Backfill a size the ingest path could not read -- a watch folder that
        // was briefly unavailable, say.
        if ($item->file_size === null) {
            $size = @filesize($path);

            if ($size !== false) {
                $item->forceFill(['file_size' => $size])->saveQuietly();
            }
        }

        return StageOutcome::done();
    }
}
