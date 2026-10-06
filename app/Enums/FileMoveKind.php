<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Enums;

/** What a journalled file operation was (#465). */
enum FileMoveKind: string
{
    /** Into the library tree, or between folders within it. */
    case File = 'file';

    /** Same folder, new name — including a case-only correction. */
    case Rename = 'rename';

    /** To the trash. The reversible form of a delete (#464). */
    case Trash = 'trash';

    /** An original set aside once a playable conversion took its place. */
    case Archive = 'archive';

    /** Restored from the trash, or undone. Recorded so an undo is itself audited. */
    case Restore = 'restore';

    public function label(): string
    {
        return match ($this) {
            self::File => 'Filed',
            self::Rename => 'Renamed',
            self::Trash => 'Moved to trash',
            self::Archive => 'Archived',
            self::Restore => 'Restored',
        };
    }
}
