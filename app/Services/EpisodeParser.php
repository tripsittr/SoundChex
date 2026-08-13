<?php

namespace App\Services;

/**
 * Recognises a television episode from its filename.
 *
 * Extension cannot tell a film from an episode — both are .mkv — so the
 * filename is the only signal available at scan time. Everything here returns
 * null rather than guessing: a file filed as the wrong episode looks correct
 * and is far harder to notice than one left in the inbox.
 */
class EpisodeParser
{
    /**
     * Ordered by how unambiguous each form is.
     *
     * `S01E02` first because it is near-universal in release naming and cannot
     * be mistaken for anything else. The looser forms follow, and the loosest
     * — a bare `102` meaning season 1 episode 2 — is deliberately absent: it
     * collides with resolutions, years, and track numbers.
     */
    private const PATTERNS = [
        // S01E02, s01e02, S1E2, S01.E02, S01 E02
        '/\bS(?<season>\d{1,2})[\s._-]?E(?<episode>\d{1,3})\b/i',

        // 1x02, 01x02
        '/\b(?<season>\d{1,2})x(?<episode>\d{1,3})\b/i',

        // Season 1 Episode 2, Season.1.Episode.2
        '/\bSeason[\s._-]*(?<season>\d{1,2})[\s._-]*Episode[\s._-]*(?<episode>\d{1,3})\b/i',

        // Series 1 Episode 2 — the British form
        '/\bSeries[\s._-]*(?<season>\d{1,2})[\s._-]*Episode[\s._-]*(?<episode>\d{1,3})\b/i',
    ];

    /**
     * Multi-episode files: S01E01-E02, S01E01E02, 1x01-1x02.
     *
     * Detected so they can be left alone rather than filed as whichever
     * episode happened to match first.
     */
    private const MULTI_EPISODE = [
        '/\bS\d{1,2}[\s._-]?E\d{1,3}[\s._-]*(?:-|E|to)[\s._-]*E?\d{1,3}\b/i',
        '/\b\d{1,2}x\d{1,3}[\s._-]*-[\s._-]*\d{1,2}?x?\d{1,3}\b/i',
    ];

    /**
     * Pulls season, episode and series name out of a filename.
     *
     * @return array{series: string, season: int, episode: int}|null
     */
    public function parse(string $filename): ?array
    {
        $name = pathinfo($filename, PATHINFO_FILENAME);

        // A file covering two episodes has no single correct destination, so
        // it is not an episode as far as filing is concerned.
        if ($this->isMultiEpisode($name)) {
            return null;
        }

        foreach (self::PATTERNS as $pattern) {
            if (! preg_match($pattern, $name, $match, PREG_OFFSET_CAPTURE)) {
                continue;
            }

            $season = (int) $match['season'][0];
            $episode = (int) $match['episode'][0];

            // Season 0 is the convention for specials, which have their own
            // rules; left in the inbox rather than filed as season zero.
            if ($season < 1 || $episode < 1) {
                return null;
            }

            $series = $this->seriesName(substr($name, 0, $match[0][1]));

            if ($series === null) {
                return null;
            }

            return [
                'series' => $series,
                'season' => $season,
                'episode' => $episode,
            ];
        }

        return null;
    }

    public function isEpisode(string $filename): bool
    {
        return $this->parse($filename) !== null;
    }

    public function isMultiEpisode(string $filename): bool
    {
        $name = pathinfo($filename, PATHINFO_FILENAME);

        foreach (self::MULTI_EPISODE as $pattern) {
            if (preg_match($pattern, $name)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The series name is whatever precedes the episode marker.
     *
     * Release names put it first without exception — "The.Bear.S01E02.1080p"
     * — so everything to the left is the title, and everything to the right is
     * quality tags and release group.
     */
    private function seriesName(string $prefix): ?string
    {
        // Separators stand in for spaces in almost every release name.
        $name = str_replace(['.', '_'], ' ', $prefix);

        // A trailing year belongs to the series ("The Office 2005"), but as a
        // folder name it adds nothing and differs between sources.
        $name = preg_replace('/\s*[\(\[]?\b(19|20)\d{2}\b[\)\]]?\s*$/', '', $name) ?? $name;

        $name = trim(preg_replace('/\s{2,}/', ' ', $name) ?? $name);
        $name = trim($name, " -–—_.");

        return $name === '' ? null : $name;
    }
}
