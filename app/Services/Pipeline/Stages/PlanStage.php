<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Services\Pipeline\Stages;

use App\Enums\FileMoveKind;
use App\Enums\FileMoveState;
use App\Jobs\Pipeline\StageOutcome;
use App\Models\FileMove;
use App\Models\MediaItem;
use App\Services\FileIdentity;
use App\Services\FileMoveJournal;
use App\Services\LibraryOrganizer;
use App\Services\LibrarySettings;
use App\Services\Pipeline\Stage;
use Illuminate\Support\Facades\Storage;

/**
 * Works out where the file should live, and writes that down (#489).
 *
 * Split from the filing itself because a plan is reviewable: it is what a dry
 * run prints, what a person approves before 8,000 files move, and what an undo
 * reverses. The old design computed the target and moved the file in one
 * breath, so there was never a moment at which the intention existed without
 * the action.
 *
 * Nothing on disk changes here. The journal row is written in `planned`.
 */
class PlanStage implements Stage
{
    public function __construct(
        private LibraryOrganizer $organizer,
        private FileMoveJournal $journal,
        private LibrarySettings $settings,
    ) {}

    public function run(MediaItem $item): StageOutcome
    {
        if (! $this->settings->autoOrganize()) {
            // The admin has filing switched off. Not a failure, and not
            // something to park a person with -- the file simply stays put
            // (#489 made this toggle work at all).
            return StageOutcome::skipped('auto-organize is off');
        }

        // The confidence gate, which #489 and #489 tightened. An item that
        // cannot be filed confidently is not a failure: its metadata is saved
        // and it is perfectly usable where it is.
        if (! $this->organizer->canOrganize($item)) {
            return StageOutcome::skipped('not confident enough to file, or missing what the path needs');
        }

        $source = $item->absoluteFilePath();
        $target = $this->organizer->targetPath($item);

        if ($source === null || $target === null) {
            return StageOutcome::skipped('no target path could be derived');
        }

        $absoluteTarget = Storage::path($target);

        // Already where it belongs. By identity, not by string, for the reason
        // #489 exists.
        if (FileIdentity::same($source, $absoluteTarget)) {
            return StageOutcome::skipped('already filed');
        }

        // Re-running this stage must not stack up plans for the same move.
        $existing = FileMove::where('media_item_id', $item->id)
            ->where('state', FileMoveState::Planned)
            ->where('to_path', $absoluteTarget)
            ->exists();

        if ($existing) {
            return StageOutcome::done();
        }

        $this->journal->plan(
            $item,
            $source,
            $absoluteTarget,
            FileIdentity::sameInode($source, $absoluteTarget)
                ? FileMoveKind::Rename
                : FileMoveKind::File,
        );

        return StageOutcome::done();
    }
}
