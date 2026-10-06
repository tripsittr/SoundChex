<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Enums;

/**
 * How a file entered the library (#489).
 *
 * Recorded because the five import paths had genuinely diverged — the scanner
 * hashed, checked duplicates, recorded intake history, fired the catalogued
 * event and queued artwork; `library:import-music`, the two admin upload
 * actions and the CSV import did some subset of that. An item's later
 * behaviour depended on which door it came through, which is not a property
 * anyone chose.
 *
 * They all go through `LibraryIngest::accept()` now, and this says which door
 * it was, so a bug reported as "uploaded files never get artwork" is
 * answerable from the row.
 */
enum IngestOrigin: string
{
    /** The scheduled watch-folder scan. */
    case Scan = 'scan';

    /** An admin panel upload. Already complete, so no settle wait. */
    case Upload = 'upload';

    /** `library:import-music` and friends. */
    case Command = 'command';

    /** A row created from a CSV. */
    case Csv = 'csv';

    /** Received from another SoundChex server. */
    case Transfer = 'transfer';

    /** Created by hand in the admin panel. */
    case Manual = 'manual';

    /**
     * Whether a file from this origin is finished being written.
     *
     * The scanner waits for an mtime to settle because a file may still be
     * downloading. An upload that has returned, a completed transfer and a
     * CLI import of an existing file are all finished by definition — and
     * `BulkUpload` scanning immediately meant uploads were *always* judged
     * unsettled and skipped.
     */
    public function isSettled(): bool
    {
        return $this !== self::Scan;
    }

    public function label(): string
    {
        return match ($this) {
            self::Scan => 'Watch folder scan',
            self::Upload => 'Upload',
            self::Command => 'Command line',
            self::Csv => 'CSV import',
            self::Transfer => 'Server transfer',
            self::Manual => 'Added by hand',
        };
    }
}
