<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Why a pair was flagged as duplicates, in descending confidence.
 *
 * The distinction that matters most is `Bytes` vs everything else: a byte match
 * is the *same file* and can be deleted safely after a byte re-compare, while a
 * content match (same recording, different file — a FLAC and an MP3, say) is two
 * different files and may only be removed by the user's explicit choice of which
 * copy to keep. `isContent()` draws that line.
 */
enum DuplicateMatch: string implements HasColor, HasLabel
{
    /** Byte-for-byte identical files. Safe to auto-delete after a re-compare. */
    case Bytes = 'bytes';

    /** Same ISRC — the recording's standard code. Near-certain same recording. */
    case Isrc = 'isrc';

    /** Same MusicBrainz recording id. */
    case MusicBrainz = 'musicbrainz';

    /** Same AcoustID acoustic fingerprint — same audio even without tags. */
    case AcoustId = 'acoustid';

    /** Close tag match: same artist + title (+ album) and near-equal duration. */
    case Fuzzy = 'fuzzy';

    /**
     * Same artist and title, but the release or the length disagrees (S-339).
     *
     * The strict fuzzy pass above demands an equal album and a length within
     * tolerance, which is right for deciding a merge and wrong for finding
     * everything worth a look: a greatest-hits copy and the original album cut
     * are the same song to a listener, and were being missed entirely. This is
     * only ever offered for review — never auto-merged — because sometimes it
     * really is a different recording.
     */
    case Likely = 'likely';

    public function getLabel(): string
    {
        return match ($this) {
            self::Bytes => 'Identical file',
            self::Isrc => 'Same ISRC',
            self::MusicBrainz => 'Same MusicBrainz recording',
            self::AcoustId => 'Same audio fingerprint',
            self::Fuzzy => 'Same track (tags + length)',
            self::Likely => 'Probably the same track',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Bytes => 'success',
            self::Isrc => 'success',
            self::MusicBrainz => 'success',
            self::AcoustId => 'info',
            self::Fuzzy => 'warning',
            self::Likely => 'gray',
        };
    }

    /**
     * Whether this is a content match — two different files holding the same
     * recording — rather than a byte-identical copy.
     *
     * The merge path uses this: a content match is never deleted by the
     * automatic byte comparison (the files differ *by definition*), only by the
     * user choosing which copy to keep.
     */
    public function isContent(): bool
    {
        return $this !== self::Bytes;
    }

    /** How sure we are, for sorting the review list worst-first. */
    public function confidence(): int
    {
        return match ($this) {
            self::Bytes => 100,
            self::Isrc => 95,
            self::MusicBrainz => 90,
            self::AcoustId => 85,
            self::Fuzzy => 60,
            self::Likely => 40,
        };
    }
}
