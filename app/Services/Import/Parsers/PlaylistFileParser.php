<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Services\Import\Parsers;

use App\Services\Import\ImportedTrack;

/**
 * Parses one playlist file format into normalised tracks (S-310).
 *
 * One implementation per format (M3U, CSV, XSPF). Each is pure PHP with no
 * external dependency, so importing a playlist file needs nothing installed.
 */
interface PlaylistFileParser
{
    /** Whether this parser handles a file with the given extension and contents. */
    public function supports(string $extension, string $contents): bool;

    /**
     * The playlist's name, if the file carries one (many do not).
     */
    public function name(string $contents): ?string;

    /**
     * The tracks, in order.
     *
     * @return array<int, ImportedTrack>
     */
    public function parse(string $contents): array;
}
