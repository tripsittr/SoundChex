<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Enums;

enum ProcessingStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Complete = 'complete';
    case Failed = 'failed';
    case NeedsReview = 'needs_review';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Processing => 'Processing',
            self::Complete => 'Complete',
            self::Failed => 'Failed',
            self::NeedsReview => 'Needs Review',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Pending => 'gray',
            self::Processing => 'info',
            self::Complete => 'success',
            self::Failed => 'danger',
            self::NeedsReview => 'warning',
        };
    }
}
