<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Enums;

/**
 * How far a journalled move got (#489).
 *
 * `Started` is the state that makes recovery possible: a row sitting in it
 * means the process died mid-move, and the reconciler has the device, inode and
 * hash recorded beforehand to work out which half happened.
 */
enum FileMoveState: string
{
    /** Decided, not yet acted on. What a dry run shows. */
    case Planned = 'planned';

    /** The move is in progress, or the process died during it. */
    case Started = 'started';

    /** The file is at `to_path` and the row knows it. */
    case Done = 'done';

    /** Reversed by an undo. */
    case Undone = 'undone';

    /** Could not be completed. `error` says why; the file is at `from_path`. */
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Planned => 'Planned',
            self::Started => 'In progress',
            self::Done => 'Done',
            self::Undone => 'Undone',
            self::Failed => 'Failed',
        };
    }
}
