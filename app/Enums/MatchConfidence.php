<?php

namespace App\Enums;

/**
 * How sure the pipeline is that it identified the right item.
 *
 * This gates file renaming: the organizer moves and renames files from
 * resolved metadata, so acting on a guess would refile a user's book under the
 * wrong author. Only `Exact` is trusted to move a file automatically.
 */
enum MatchConfidence: string
{
    /** Matched on an identifier (ISBN, MBID, TMDB id) or a verbatim title. */
    case Exact = 'exact';

    /**
     * Matched only after normalising — punctuation, censored words, a dropped
     * subtitle. Usually right, but not enough to rename a file over.
     */
    case Fuzzy = 'fuzzy';

    /** Nothing matched; metadata came from file tags or the filename. */
    case None = 'none';

    public function label(): string
    {
        return match ($this) {
            self::Exact => 'Exact match',
            self::Fuzzy => 'Likely match',
            self::None => 'No match',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Exact => 'success',
            self::Fuzzy => 'warning',
            self::None => 'gray',
        };
    }

    /** Whether this is certain enough to rename and relocate a file. */
    public function allowsFileMove(): bool
    {
        return $this === self::Exact;
    }
}
