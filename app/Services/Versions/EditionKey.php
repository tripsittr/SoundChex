<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Services\Versions;

use App\Models\MediaItem;

/**
 * Which version of a work a file is (#489).
 *
 * The rule the user set: *"If Spotify has 15 versions of a song for an artist,
 * we should too."* A remaster, a single edit, a live cut, an acoustic take and
 * the album version are **five distinct things to keep**, not five copies of
 * one — so what separates them has to be read rather than discarded.
 *
 * This is the load-bearing part of the version model. If edition detection is
 * weak, two genuinely different recordings get the same key and the dedupe
 * stage offers to merge them; if it invents editions, versions that should
 * group are split. Both are losses, and the second is the quieter one.
 *
 * Null means "the plain release", which is the common case and is *not* the
 * same as unknown — a file with no edition marker is the album version.
 */
class EditionKey
{
    /**
     * Edition markers, as a normalised phrase to the key it means.
     *
     * Ordered longest-first at match time, so "deluxe edition" is not read as
     * "edition" and "remastered 2012" keeps its year.
     */
    private const MARKERS = [
        'single version' => 'single',
        'album version' => null,
        'radio edit' => 'radio_edit',
        'extended mix' => 'extended',
        'extended version' => 'extended',
        'deluxe edition' => 'deluxe',
        'anniversary edition' => 'anniversary',
        'directors cut' => 'directors_cut',
        'director s cut' => 'directors_cut',
        'theatrical cut' => 'theatrical',
        'unrated' => 'unrated',
        'acoustic' => 'acoustic',
        'instrumental' => 'instrumental',
        'a cappella' => 'acappella',
        'acappella' => 'acappella',
        'demo' => 'demo',
        'live' => 'live',
        'remix' => 'remix',
        'edit' => 'edit',
        'single' => 'single',
        'mono' => 'mono',
        'stereo' => 'stereo',
        'sped up' => 'sped_up',
        'slowed' => 'slowed',
        'reprise' => 'reprise',
        'remaster' => 'remaster',
        'remastered' => 'remaster',
        'remasterizado' => 'remaster',
        'extended' => 'extended',
        'imax' => 'imax',
    ];

    /**
     * The edition key for an item, or null for the plain release.
     *
     * Reads the title's own suffix — which `MediaItem::editionSuffix()` has
     * already proven is an edition rather than the artist's name written into
     * the title (#489).
     */
    public function for(MediaItem $item): ?string
    {
        $suffix = $item->editionSuffix();

        if ($suffix === null) {
            // Also worth reading: a parenthesised marker, which is how
            // streaming services usually write it — "Song (Live)".
            $suffix = $this->parenthesised((string) $item->title);
        }

        return $suffix === null ? null : $this->keyFor($suffix);
    }

    /**
     * The key a marker phrase means, or null when it names no edition.
     *
     * A year is kept with the marker — `remaster_2012` — because a 2009 and a
     * 2012 remaster are different masters and merging them loses one.
     */
    public function keyFor(string $phrase): ?string
    {
        $normalised = $this->normalise($phrase);

        if ($normalised === '') {
            return null;
        }

        $year = $this->yearIn($normalised);

        // Longest marker first, so "deluxe edition" wins over "edition" and
        // "single version" over "single".
        $markers = self::MARKERS;
        uksort($markers, fn (string $a, string $b): int => mb_strlen($b) <=> mb_strlen($a));

        foreach ($markers as $marker => $key) {
            if (! str_contains($normalised, $marker)) {
                continue;
            }

            if ($key === null) {
                // "Album version" names the plain release explicitly, which is
                // the same thing as no edition at all.
                return null;
            }

            return $year !== null && in_array($key, ['remaster', 'anniversary'], true)
                ? $key.'_'.$year
                : $key;
        }

        // A suffix that matches nothing known. Kept rather than discarded,
        // slugified so it can be compared -- a marker this code has not met
        // still distinguishes two files, and treating it as "no edition" would
        // merge them. That is the direction the user's rule demands.
        return $this->slug($normalised);
    }

    /**
     * A marker inside brackets, which is how streaming services write one.
     *
     * Only when the bracketed text is a *known* marker: "(feat. Someone)" is a
     * credit, not an edition, and "(Remastered)" is.
     */
    private function parenthesised(string $title): ?string
    {
        if (preg_match_all('/[\(\[]([^\)\]]+)[\)\]]/u', $title, $matches) < 1) {
            return null;
        }

        foreach ($matches[1] as $inner) {
            $normalised = $this->normalise($inner);

            if ($normalised === '' || str_starts_with($normalised, 'feat') || str_starts_with($normalised, 'ft ')) {
                continue;
            }

            foreach (array_keys(self::MARKERS) as $marker) {
                if (str_contains($normalised, $marker)) {
                    return $inner;
                }
            }
        }

        return null;
    }

    /** Lower-cased, punctuation flattened to spaces, collapsed. */
    private function normalise(string $value): string
    {
        $value = mb_strtolower(trim($value));
        $value = (string) preg_replace('/[^a-z0-9]+/', ' ', $value);

        return trim((string) preg_replace('/\s+/', ' ', $value));
    }

    private function yearIn(string $normalised): ?string
    {
        return preg_match('/\b(19\d{2}|20\d{2})\b/', $normalised, $match) === 1
            ? $match[1]
            : null;
    }

    private function slug(string $normalised): string
    {
        return (string) preg_replace('/\s+/', '_', $normalised);
    }
}
