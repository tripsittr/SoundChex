<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Services;

/**
 * A file could not be catalogued (#489).
 *
 * Thrown rather than returned as null, because `accept()` already uses null for
 * "this path is already catalogued" -- an ordinary, successful outcome. Folding
 * a database error into the same value made a dropped file indistinguishable
 * from a file that needed nothing done, which is precisely the "distinguish
 * nothing-to-do from failed" rule (AGENTS.md rule 2).
 *
 * Callers that can act on it should: a command should fail loudly rather than
 * report importing nothing, while the scanner catches it per file so one bad
 * file does not end a scan of ten thousand.
 */
class IngestFailed extends \RuntimeException {}
