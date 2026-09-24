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
        // A tagger that writes the whole credit into the title writes it as it
        // reads — "A, B - Song", "A & B - Song" — which no single artist name
        // matches. Offer those joinings too, so the combined prefix strips
        // instead of surviving because neither name alone was the whole of it
        // (S-365).
        // A tagger records a collaboration however it likes: the artist field
        // reads "$uicideboy$/Maxo Cream" while the title says "$uicideboy$,
        // Maxo Cream - Song". Neither string matches the other, so split every
        // credit into its individual names and offer the names back joined by
        // each separator a tagger might have chosen (S-365). One of the
        // spellings is the one in the title.
        $names = [];

        foreach ($candidates as $credit) {
            foreach (preg_split('/\s*(?:\/|,|&| and | x | feat\.? | ft\.? )\s*/iu', $credit) ?: [] as $name) {
                $name = trim((string) $name);

                if ($name !== '' && ! in_array($name, $names, true)) {
                    $names[] = $name;
                }
            }
        }

        $candidates = array_merge($candidates, $names);

        if (count($names) > 1) {
            // Join the names themselves, never the growing list — each glue
            // must see the same names, not the joins made before it.
            foreach ([', ', ' & ', ' and ', ' x ', ' / ', ' feat. ', ' ft. '] as $glue) {
                $candidates[] = implode($glue, $names);
            }
        }

        $candidates = array_values(array_unique($candidates));

        // Longest first, so the fullest credit strips before a shorter one
        // leaves the rest of it behind.
        usort($candidates, fn ($a, $b) => mb_strlen($b) <=> mb_strlen($a));

        $seps = implode('', array_map(fn ($s) => preg_quote($s, '/'), self::SEPARATORS));

        foreach ($candidates as $artist) {
            // Underscores and spaces are the same separator to a tagger —
            // "Plague_tsc" in the artist field, "Plague tsc" in the title —
            // so treat either as matching either (S-365).
            // preg_quote leaves a space alone, so match on the literal space
            // rather than an escaped one.
            $quoted = str_replace(
                ['_', ' '],
                '[_\s]',
                preg_quote($artist, '/'),
            );

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
