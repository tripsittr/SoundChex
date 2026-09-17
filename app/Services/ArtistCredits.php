<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

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

        // One code path. The splitter already keeps known names and
        // generational suffixes whole, and a second set of guards here could
        // disagree with it — which is how "Earth, Wind & Fire" came to be
        // protected by primary() and shattered by all().
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
        $credit = trim($credit);

        if ($credit === '') {
            return [];
        }

        // Known names are taken out wherever they appear, not only at the
        // front. A band billed second — "Santana, Earth, Wind & Fire" — would
        // otherwise be shattered into "Earth" and "Wind & Fire", inventing two
        // artists who do not exist.
        $names = [];
        $offset = 0;
        $length = mb_strlen($credit);

        while ($offset < $length) {
            $match = $this->indivisibleAt($credit, $offset);

            if ($match !== null) {
                $names[] = $match;
                $offset += mb_strlen($match);
                $offset += $this->separatorLength($credit, $offset);

                continue;
            }

            // Read up to the next separator.
            $next = $this->nextSeparator($credit, $offset);
            $piece = trim(mb_substr($credit, $offset, $next - $offset));

            if ($piece !== '') {
                $names[] = $piece;
            }

            $offset = $next + $this->separatorLength($credit, $next);
        }

        // A fragment that is only a generational suffix belongs to the name
        // before it — "Hank Williams" + "Jr." is one artist, in any position.
        $joined = [];

        foreach ($names as $name) {
            if ($joined !== [] && $this->isSuffix($name)) {
                $joined[count($joined) - 1] .= ', ' . $name;

                continue;
            }

            $joined[] = $name;
        }

        return $joined;
    }

    /**
     * A known name starting exactly at this offset, if any.
     *
     * Longest wins, so "Crosby, Stills, Nash & Young" is not read as the
     * shorter "Crosby, Stills & Nash".
     */
    private function indivisibleAt(string $credit, int $offset): ?string
    {
        $found = null;

        foreach (self::INDIVISIBLE as $name) {
            $candidate = mb_substr($credit, $offset, mb_strlen($name));

            if (mb_strtolower($candidate) !== mb_strtolower($name)) {
                continue;
            }

            // The match has to end the string or be followed by a separator,
            // or "America" swallows the start of "American Authors".
            $after = mb_substr($credit, $offset + mb_strlen($name));

            if ($after !== '' && ! preg_match('/^\s*[,\/]/u', $after)) {
                continue;
            }

            if ($found === null || mb_strlen($name) > mb_strlen($found)) {
                $found = $name;
            }
        }

        return $found;
    }

    private function nextSeparator(string $credit, int $offset): int
    {
        $length = mb_strlen($credit);

        for ($i = $offset; $i < $length; $i++) {
            if (in_array(mb_substr($credit, $i, 1), [',', '/'], true)) {
                return $i;
            }
        }

        return $length;
    }

    /**
     * How much separator and whitespace sits at this offset.
     */
    private function separatorLength(string $credit, int $offset): int
    {
        $rest = mb_substr($credit, $offset);

        return preg_match('/^\s*[,\/]\s*/u', $rest, $m) ? mb_strlen($m[0]) : 0;
    }

    private function isSuffix(string $part): bool
    {
        $bare = mb_strtolower(rtrim(trim($part), '.'));

        foreach (self::SUFFIXES as $suffix) {
            if ($bare === mb_strtolower($suffix)) {
                return true;
            }
        }

        return false;
    }

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
