<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Jobs\Pipeline;

/**
 * What a stage did (#489).
 *
 * The audit's third structural fault was that stages returned `null` for every
 * outcome: "nothing to do", "failed", "needs a person" and "try again later"
 * were one value. The organizer reported 85 successes and one silent failure
 * that way, and a provider being down read as "no match".
 *
 * Every stage now returns exactly one of these, and `null` is not among them.
 */
enum StageResult
{
    /** The work happened. Continue. */
    case Done;

    /** Nothing to do, legitimately -- no cover to embed, no subtitles. Continue. */
    case Skipped;

    /** A person has to decide. Park with a reason; do not continue. */
    case NeedsReview;

    /** Transient -- a rate limit, a lock, a busy disk. Try this stage again. */
    case Retry;

    /** Cannot be completed by repeating. Stop and record why. */
    case Failed;
}
