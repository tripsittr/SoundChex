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

    /**
     * Same film, by the provider's id for it.
     *
     * Video had no content pass at all, so the only duplicate a film could
     * have was a byte-identical one — and a second rip never is. Two rows both
     * titled "War Dogs", both resolved to TMDB 308266, one 18 MB and one
     * 1.8 GB, were invisible to a detector that only compared bytes.
     */
    case Tmdb = 'tmdb';

    /**
     * Same episode of the same series, by season and episode number.
     *
     * The strongest identity an episode has, and more reliable than its title:
     * series reuse titles across seasons, and numbering does not.
     */
    case Episode = 'episode';

    /**
     * Same title and year, with no provider id to confirm it.
     *
     * For a film or an episode that enrichment has not identified. Offered for
     * review only: two different films do share a title, which is exactly why
     * the year is required as well.
     */
    case SameTitle = 'same_title';

    public function getLabel(): string
    {
        return match ($this) {
            self::Bytes => 'Identical file',
            self::Isrc => 'Same ISRC',
            self::MusicBrainz => 'Same MusicBrainz recording',
            self::AcoustId => 'Same audio fingerprint',
            self::Fuzzy => 'Same track (tags + length)',
            self::Likely => 'Probably the same track',
            self::Tmdb => 'Same film (TMDB)',
            self::Episode => 'Same episode',
            self::SameTitle => 'Same title and year',
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
            self::Tmdb => 'success',
            self::Episode => 'success',
            self::SameTitle => 'warning',
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

    /**
     * Whether a match of this kind may be resolved in bulk, without a person
     * looking at the specific pair.
     *
     * `Likely` and `SameTitle` may not. Both are deliberately loose -- a shared
     * artist and title with a *differing* album or length, or a shared title and
     * year with no provider id at all -- and both exist to surface things worth
     * a look, never to decide them. A bulk "merge selected" over them deletes a
     * different song's or film's file, which is exactly what LibraryCleanup.md
     * ruled out and what the table did anyway (#461).
     *
     * An identifier match (bytes, ISRC, MBID, AcoustID, TMDB, episode number)
     * is specific enough to act on en masse. `Fuzzy` requires an equal album
     * and a near-equal length, which is the bar for a merge.
     */
    public function allowsBulkResolution(): bool
    {
        return match ($this) {
            self::Likely, self::SameTitle => false,
            default => true,
        };
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
            // A provider id is as good as an ISRC: it identifies the work.
            self::Tmdb => 95,
            self::Episode => 95,
            self::SameTitle => 55,
        };
    }
}
