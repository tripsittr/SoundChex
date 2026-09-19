<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Services;

/**
 * Removes an artist that a song title is carrying as a "-" prefix or suffix.
 *
 * "$uicideboy$ - Converting" → "Converting"; "Gold - Imagine Dragons" → "Gold".
 * Libraries imported from "Artist - Title.mp3" filenames are full of these.
 *
 * Deliberately conservative: the artist must be a whole segment on one side of a
 * dash-style separator, and the remainder must be non-empty — so a title that
 * legitimately *is* the artist ("Neon Trees" by Neon Trees) or merely contains
 * the name ("In A Big Country" by Big Country) is never touched.
 */
class TitleTidier
{
    /** Dash-style separators that divide an artist from a title. */
    private const SEPARATORS = ['-', '–', '—'];

    /**
     * The cleaned title, or null when there is nothing to strip.
     *
     * @param  iterable<int, string|null>  $artists  Candidate artist strings.
     */
    public function strip(string $title, iterable $artists): ?string
    {
        $title = trim($title);

        if ($title === '') {
            return null;
        }

        // Longest first, so a full "Artist, Feat" credit strips before the bare
        // primary would leave "Feat - Song" behind.
        $candidates = array_values(array_unique(array_filter(
            array_map(fn ($a) => trim((string) $a), (array) $artists),
            fn (string $a) => $a !== '',
        )));
        usort($candidates, fn ($a, $b) => mb_strlen($b) <=> mb_strlen($a));

        $seps = implode('', array_map(fn ($s) => preg_quote($s, '/'), self::SEPARATORS));

        foreach ($candidates as $artist) {
            $quoted = preg_quote($artist, '/');

            // Prefix: "Artist - Title"
            if (preg_match('/^'.$quoted.'\s*['.$seps.']\s+(?<rest>.+)$/iu', $title, $m)) {
                $rest = trim($m['rest']);
                if ($rest !== '') {
                    return $rest;
                }
            }

            // Suffix: "Title - Artist"
            if (preg_match('/^(?<rest>.+?)\s+['.$seps.']\s*'.$quoted.'$/iu', $title, $m)) {
                $rest = trim($m['rest']);
                if ($rest !== '') {
                    return $rest;
                }
            }
        }

        return null;
    }
}
