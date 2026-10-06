<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Enums;

/**
 * Why the *system* parked an item for a person (#489).
 *
 * Separate from `ReviewReason`, which is what a **user** picked when they sent
 * something back. That enum is deliberately short and its five values are
 * already shown in every client, so adding ten system cases to it would break
 * a contract the apps depend on. The two live side by side in `review_items`
 * and `source` says which applies.
 *
 * Every one of these is something that previously left an item **hidden from
 * the library and absent from review at once** — the audit found seven such
 * paths, and each had nowhere to record itself. A reason code is the minimum
 * needed to turn "something is wrong somewhere" into a job somebody can do.
 */
enum SystemReviewReason: string
{
    /** Identification scored below the threshold. Nothing matched. */
    case NoMatch = 'no_match';

    /** Several candidates scored alike, so picking one would be a coin toss. */
    case AmbiguousMatch = 'ambiguous_match';

    /** Matched, but loosely — a text search rather than an identifier. */
    case LowConfidence = 'low_confidence';

    /** Matched to a compilation or an undated release, not the studio original. */
    case CompilationOrUndated = 'compilation_or_undated';

    /** A music track with no album: a single, or a tag that failed to read. */
    case MissingAlbum = 'missing_album';

    /** Same work, same edition, same quality as something already here. */
    case Duplicate = 'duplicate';

    /** A quality check found something bad: truncated, no audio, a CAM rip. */
    case Quality = 'quality';

    /** ffprobe could not read the file, so it may be corrupt or not media. */
    case Unreadable = 'unreadable';

    /** Two copies carried different art, so the kept cover is a guess. */
    case CoverUncertain = 'cover_uncertain';

    /** No target path could be derived — the metadata a path needs is missing. */
    case CannotFile = 'cannot_file';

    /** The move failed. The journal says which half happened. */
    case MoveFailed = 'move_failed';

    /** The row points at a file that is not there. */
    case MissingFile = 'missing_file';

    /** A stage exhausted its attempts. `pipeline_error` says why. */
    case Stuck = 'stuck';

    public function label(): string
    {
        return match ($this) {
            self::NoMatch => 'Not identified',
            self::AmbiguousMatch => 'Several possible matches',
            self::LowConfidence => 'Loose match',
            self::CompilationOrUndated => 'Matched to a compilation',
            self::MissingAlbum => 'No album',
            self::Duplicate => 'Duplicate',
            self::Quality => 'Quality problem',
            self::Unreadable => 'Unreadable file',
            self::CoverUncertain => 'Uncertain cover',
            self::CannotFile => 'Cannot be filed',
            self::MoveFailed => 'Move failed',
            self::MissingFile => 'File missing',
            self::Stuck => 'Stuck',
        };
    }

    /**
     * Which job on the review screen this belongs to.
     *
     * The screen offers four jobs rather than thirteen reasons, because a
     * reason is the system's taxonomy and a job is what somebody sets out to
     * do (#489). This is the mapping between them.
     */
    public function job(): string
    {
        return match ($this) {
            self::NoMatch, self::AmbiguousMatch, self::LowConfidence,
            self::CompilationOrUndated, self::MissingAlbum => 'identify',

            self::Duplicate => 'duplicates',

            self::CoverUncertain => 'covers',

            self::Quality, self::Unreadable, self::CannotFile,
            self::MoveFailed, self::MissingFile, self::Stuck => 'files',
        };
    }

    /**
     * Which pipeline stage resolving this should resume from.
     *
     * Resolving a review item is not just closing it: picking a candidate means
     * the item needs enriching again, while accepting a quality finding means
     * it only needs filing. Resuming at the right stage is the difference
     * between a second of work and re-running the whole pipeline.
     */
    public function resumeAt(): PipelineStage
    {
        return match ($this) {
            self::NoMatch, self::AmbiguousMatch, self::LowConfidence,
            self::CompilationOrUndated => PipelineStage::Identified,

            self::MissingAlbum, self::CoverUncertain => PipelineStage::Enriched,

            self::Duplicate => PipelineStage::Deduped,

            self::Quality => PipelineStage::Checked,

            self::CannotFile, self::MoveFailed => PipelineStage::Planned,

            // A file that could not be read or found has to be looked at again
            // from the beginning: whatever replaced it is a different file.
            self::Unreadable, self::MissingFile, self::Stuck => PipelineStage::Catalogued,
        };
    }

    /**
     * Whether this reason hides the item from the library while it is open.
     *
     * Most do not. An item with a loose match or no album is perfectly usable
     * and should be visible while somebody gets round to it -- hiding it is
     * what made 8,440 rows invisible. Only a file that cannot be played is
     * worth withholding.
     */
    public function hidesItem(): bool
    {
        return match ($this) {
            self::Unreadable, self::MissingFile, self::Quality => true,
            default => false,
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Duplicate, self::CoverUncertain, self::MissingAlbum => 'warning',
            self::Unreadable, self::MissingFile, self::MoveFailed, self::Stuck, self::Quality => 'danger',
            default => 'gray',
        };
    }
}
