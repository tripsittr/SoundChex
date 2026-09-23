<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Filament\Resources\Concerns;

use App\Enums\ProcessingStatus;

/**
 * The statuses that put an item in front of a human.
 *
 * One definition because several screens count and filter on the same pair and
 * must agree about it: the Needs Review sidebar badge, the tabs on that page,
 * and the per-type "Needs review" tab all claim to show the same work. A screen
 * that added Failed while another did not would report different totals for
 * what is meant to be one queue.
 */
trait HasNeedsReviewStatuses
{
    /**
     * Could not be identified confidently, or the pipeline gave up outright.
     *
     * @return array<int, string>
     */
    protected static function needsReviewStatuses(): array
    {
        return [
            ProcessingStatus::NeedsReview->value,
            ProcessingStatus::Failed->value,
        ];
    }
}
