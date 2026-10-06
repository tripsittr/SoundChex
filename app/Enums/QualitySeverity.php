<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Enums;

/**
 * How much a quality finding matters (#467).
 *
 * The distinction that does work is `Bad` vs the rest: only `Bad` parks an
 * item for review. A warning is worth recording and not worth stopping an
 * import for -- a library full of blocked files nobody asked about is how a
 * quality check gets switched off.
 */
enum QualitySeverity: string
{
    /** Worth knowing, nothing wrong. A language list, a source tag. */
    case Info = 'info';

    /** Probably fine, possibly not. A low bitrate for the resolution. */
    case Warn = 'warn';

    /** The file is damaged or misrepresented. Parks it for a person. */
    case Bad = 'bad';

    public function label(): string
    {
        return match ($this) {
            self::Info => 'Note',
            self::Warn => 'Worth a look',
            self::Bad => 'Problem',
        };
    }

    /** Filament badge colour, so severity reads at a glance. */
    public function color(): string
    {
        return match ($this) {
            self::Info => 'gray',
            self::Warn => 'warning',
            self::Bad => 'danger',
        };
    }
}
