<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Services\Pipeline\Stages;

use App\Enums\FileMoveState;
use App\Jobs\Pipeline\StageOutcome;
use App\Models\FileMove;
use App\Models\MediaItem;
use App\Services\FileMoveJournal;
use App\Services\LibraryOrganizer;
use App\Services\Pipeline\Stage;

/**
 * Carries out the plan (#465).
 *
 * Executes whatever `PlanStage` wrote, through the journal -- so the row is
 * flipped to `started` before the first byte moves and to `done` after, and a
 * process killed in between leaves a record the sweeper can reconcile.
 *
 * That is the crash window closed. Previously `rename()` could succeed and the
 * process die before the row was saved: the item still pointed at the old path,
 * the sweep called it `file_missing`, and the next scan catalogued the moved
 * file as a *new* item -- the same file in the library twice.
 */
class FileStage implements Stage
{
    public function __construct(
        private FileMoveJournal $journal,
        private LibraryOrganizer $organizer,
    ) {}

    public function run(MediaItem $item): StageOutcome
    {
        $planned = FileMove::where('media_item_id', $item->id)
            ->where('state', FileMoveState::Planned)
            ->orderBy('id')
            ->get();

        if ($planned->isEmpty()) {
            return StageOutcome::skipped('nothing planned');
        }

        $failed = [];

        foreach ($planned as $move) {
            if ($this->journal->execute($move)) {
                // The row follows the file. Clearing the hash is rule 2: the
                // stored fingerprint described the old path.
                $item->forceFill([
                    'file_path' => $this->organizer->toRelative((string) $move->to_path),
                    'content_hash' => null,
                ])->saveQuietly();

                continue;
            }

            $failed[] = (string) $move->error;
        }

        if ($failed !== []) {
            // A person has to look: the journal says exactly which half
            // happened, which is the information the old "log only" failure
            // path never produced.
            return StageOutcome::needsReview('could not file: '.implode('; ', array_unique($failed)));
        }

        return StageOutcome::done();
    }
}
