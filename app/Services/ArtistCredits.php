<?php

namespace App\Services;

/**
 * Works out which artist a track belongs to.
 *
 * Artist is one free-text string carrying every credit — "$uicideboy$, Pouya",
 * "Avicii, Nicky Romero" — so the person who made the record is a different
 * artist from the same person featuring someone else. 233 of 1,034 distinct
 * artists in the library are a combination like this.
 *
 * The first name is the primary one, which is the convention every tagger and
 * store follows. The work here is knowing when a separator is not a separator.
 */
class ArtistCredits
{
    /**
     * Names whose own punctuation would otherwise split them.
     *
     * None of these are in the library today, which is exactly why they are
     * written down: a rule that is merely correct about present data is a
     * rule that breaks silently the first time someone adds a record.
     *
     * Matched case-insensitively against the whole string.
     */
    private const INDIVISIBLE = [
        'Earth, Wind & Fire',
        'Crosby, Stills & Nash',
        'Crosby, Stills, Nash & Young',
        'Blood, Sweat & Tears',
        'Emerson, Lake & Palmer',
        'Peter, Paul and Mary',
        'Tyler, The Creator',
        'Sly, Slick & Wicked',
        'Hootie & the Blowfish',
    ];

    /**
     * Suffixes that follow a comma and belong to the name before it.
     *
     * "Hank Williams, Jr." is one artist, and is in the library.
     */
    private const SUFFIXES = ['Jr', 'Sr', 'II', 'III', 'IV', 'MD', 'PhD'];

    /**
     * The artist a track should be filed under for browsing.
     *
     * Returns null for a blank credit rather than an empty string, so callers
     * can fall back to the raw column.
     */
    public function primary(?string $credit): ?string
    {
        $credit = trim((string) $credit);

        if ($credit === '') {
            return null;
        }

        if ($this->isIndivisible($credit)) {
            return $credit;
        }

        if ($this->endsWithSuffix($credit)) {
            return $credit;
        }

        $first = preg_split('/\s*[,\/]\s*/u', $credit)[0] ?? $credit;
        $first = trim($first);

        // A credit that begins with its own separator — ", Pouya" — would
        // otherwise reduce to nothing at all.
        return $first !== '' ? $first : $credit;
    }

    /**
     * Everyone credited, primary first.
     *
     * @return list<string>
     */
    public function all(?string $credit): array
    {
        $credit = trim((string) $credit);

        if ($credit === '') {
            return [];
        }

        if ($this->isIndivisible($credit) || $this->endsWithSuffix($credit)) {
            return [$credit];
        }

        $parts = preg_split('/\s*[,\/]\s*/u', $credit) ?: [];
        $parts = array_values(array_filter(array_map('trim', $parts), fn (string $p) => $p !== ''));

        return $parts === [] ? [$credit] : $parts;
    }

    /**
     * Whether this credit names more than one artist.
     */
    public function isCollaboration(?string $credit): bool
    {
        return count($this->all($credit)) > 1;
    }

    private function isIndivisible(string $credit): bool
    {
        foreach (self::INDIVISIBLE as $name) {
            if (mb_strtolower($credit) === mb_strtolower($name)) {
                return true;
            }
        }

        return false;
    }

    /**
     * "Hank Williams, Jr." — a comma followed only by a generational suffix.
     */
    private function endsWithSuffix(string $credit): bool
    {
        $pattern = '/,\s*(' . implode('|', self::SUFFIXES) . ')\.?\s*$/i';

        return (bool) preg_match($pattern, $credit);
    }
}
