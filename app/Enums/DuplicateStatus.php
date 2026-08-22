<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Where a flagged duplicate sits in the review cycle.
 *
 * Only `Pending` means "we haven't decided yet" — the other two are decisions
 * the user made, and the scanner must not re-open either of them.
 */
enum DuplicateStatus: string implements HasColor, HasLabel
{
    /** Detected, awaiting a decision. Nothing has been deleted. */
    case Pending = 'pending';

    /** The user chose to keep both copies. Never flagged again. */
    case Kept = 'kept';

    /** The redundant file was removed and the rows merged. */
    case Merged = 'merged';

    public function getLabel(): string
    {
        return match ($this) {
            self::Pending => 'Needs review',
            self::Kept    => 'Keeping both',
            self::Merged  => 'Merged',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Pending => 'warning',
            self::Kept    => 'gray',
            self::Merged  => 'success',
        };
    }

    /**
     * Whether the scanner should leave this row alone.
     *
     * A decision the user already made — either way — is final. Re-flagging a
     * pair the user deliberately kept would make the review list impossible to
     * ever finish clearing.
     */
    public function isResolved(): bool
    {
        return $this !== self::Pending;
    }
}
