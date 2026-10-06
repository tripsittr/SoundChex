<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Jobs\Pipeline;

/**
 * A stage's verdict, with the reason when there is one (#465).
 *
 * The reason is not decoration: it becomes the item's `pipeline_error` and the
 * text a person reads in the review queue. "Needs review" with no reason is the
 * state the audit found 8,440 items in.
 */
final readonly class StageOutcome
{
    private function __construct(
        public StageResult $result,
        public ?string $reason = null,
    ) {}

    public static function done(): self
    {
        return new self(StageResult::Done);
    }

    /** Nothing to do, legitimately. The reason is for the log, not the user. */
    public static function skipped(?string $why = null): self
    {
        return new self(StageResult::Skipped, $why);
    }

    public static function needsReview(string $why): self
    {
        return new self(StageResult::NeedsReview, $why);
    }

    public static function retry(string $why): self
    {
        return new self(StageResult::Retry, $why);
    }

    public static function failed(string $why): self
    {
        return new self(StageResult::Failed, $why);
    }
}
