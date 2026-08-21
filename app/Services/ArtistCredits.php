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

        $first = trim($this->split($credit)[0] ?? $credit);

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

        $parts = $this->split($credit);

        return $parts === [] ? [$credit] : $parts;
    }

    /**
     * Whether this credit names more than one artist.
     */
    public function isCollaboration(?string $credit): bool
    {
        return count($this->all($credit)) > 1;
    }

    /**
     * Splits a credit, keeping generational suffixes attached.
     *
     * The suffix guard above only catches a name at the end of the string.
     * "Hank Williams, Jr., Reba McEntire, Willie Nelson" is a real credit in
     * the library, and splitting it naively files his work under two artists —
     * "Hank Williams, Jr." for one track and "Hank Williams" for another.
     *
     * @return list<string>
     */
    private function split(string $credit): array
    {
        // A known name is taken off the front whole, before any splitting, so
        // its own commas are never treated as separators.
        $prefix = $this->indivisiblePrefix($credit);
        $rest = $credit;

        if ($prefix !== null) {
            $rest = ltrim(mb_substr($credit, mb_strlen($prefix)), " \t,/");
        }

        $parts = $rest === '' ? [] : (preg_split('/\s*[,\/]\s*/u', $rest) ?: []);
        $parts = array_values(array_filter(array_map('trim', $parts), fn (string $p) => $p !== ''));

        if ($prefix !== null) {
            array_unshift($parts, $prefix);
        }

        $joined = [];

        foreach ($parts as $part) {
            $bare = rtrim($part, '.');

            // A fragment that is only a suffix belongs to the name before it.
            if ($joined !== [] && in_array(ucfirst(mb_strtolower($bare)), array_map('ucfirst', array_map('mb_strtolower', self::SUFFIXES)), true)) {
                $joined[count($joined) - 1] .= ', ' . $part;

                continue;
            }

            $joined[] = $part;
        }

        return $joined;
    }

    /**
     * The known name this credit begins with, if any.
     *
     * Matching the whole string is not enough: "Earth, Wind & Fire" alone was
     * protected, while "Earth, Wind & Fire, Santana" split at the first comma
     * and filed the track under "Earth". A guard that only works when the band
     * plays alone is not a guard.
     */
    private function indivisiblePrefix(string $credit): ?string
    {
        $credit = trim($credit);
        $lower = mb_strtolower($credit);

        $found = null;

        foreach (self::INDIVISIBLE as $name) {
            $needle = mb_strtolower($name);

            if ($lower === $needle) {
                return $name;
            }

            // A separator has to follow, or "America" would match inside
            // "American Authors".
            if (str_starts_with($lower, $needle) && preg_match('/^\s*[,\/]/u', mb_substr($credit, mb_strlen($name)))) {
                // Longest wins: "Crosby, Stills, Nash & Young" over
                // "Crosby, Stills & Nash" where both could match.
                if ($found === null || mb_strlen($name) > mb_strlen($found)) {
                    $found = $name;
                }
            }
        }

        return $found;
    }

    private function isIndivisible(string $credit): bool
    {
        return $this->indivisiblePrefix($credit) !== null
            && mb_strtolower($this->indivisiblePrefix($credit)) === mb_strtolower(trim($credit));
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
