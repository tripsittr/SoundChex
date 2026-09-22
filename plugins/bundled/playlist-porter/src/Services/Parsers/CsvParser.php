<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace SoundChex\PlaylistPorter\Services\Parsers;

use SoundChex\PlaylistPorter\Services\ImportedTrack;

/**
 * Parses CSV playlist exports (S-310).
 *
 * The common shape of a "download my playlist" export — Exportify (Spotify),
 * TuneMyMusic, and hand-rolled sheets all produce CSV. Column names vary, so the
 * header is matched loosely against the fields we care about (title, artist,
 * album, ISRC, Spotify id, duration), which also lets a Spotify export be
 * imported with its rich ids intact for a decisive match.
 */
class CsvParser implements PlaylistFileParser
{
    /** Header aliases → our field, matched case-insensitively on a normalised name. */
    private const COLUMNS = [
        'title' => ['track name', 'track', 'title', 'name', 'song'],
        'artist' => ['artist name(s)', 'artist name', 'artist', 'artists', 'artist(s)'],
        'album' => ['album name', 'album'],
        'isrc' => ['isrc'],
        'spotify_id' => ['spotify track id', 'track id', 'spotify id', 'spotify_id'],
        'duration_ms' => ['duration (ms)', 'duration ms', 'track duration (ms)', 'duration_ms'],
        'duration_s' => ['duration (s)', 'duration', 'length', 'time'],
    ];

    public function supports(string $extension, string $contents): bool
    {
        if (strtolower($extension) === 'csv') {
            return true;
        }

        // A comma-or-tab header with a recognisable column, even without the ext.
        $firstLine = strtok($contents, "\n") ?: '';

        return str_contains($firstLine, ',')
            && $this->mapHeader($this->splitRow($firstLine)) !== [];
    }

    public function name(string $contents): ?string
    {
        return null; // CSV exports carry rows, not a playlist name.
    }

    public function parse(string $contents): array
    {
        $lines = preg_split('/\r\n|\r|\n/', trim($contents)) ?: [];

        if ($lines === []) {
            return [];
        }

        $map = $this->mapHeader($this->splitRow(array_shift($lines)));

        if ($map === []) {
            return [];
        }

        $tracks = [];

        foreach ($lines as $line) {
            if (trim($line) === '') {
                continue;
            }

            $cells = $this->splitRow($line);

            $get = fn (string $field): ?string => isset($map[$field], $cells[$map[$field]])
                ? trim($cells[$map[$field]])
                : null;

            $durationMs = null;
            if (($ms = $get('duration_ms')) !== null && is_numeric($ms)) {
                $durationMs = (int) $ms;
            } elseif (($s = $get('duration_s')) !== null) {
                $durationMs = $this->secondsToMs($s);
            }

            $track = new ImportedTrack(
                title: $this->clean($get('title')),
                artist: $this->clean($get('artist')),
                album: $this->clean($get('album')),
                isrc: $this->clean($get('isrc')),
                spotifyId: $this->spotifyId($get('spotify_id')),
                durationMs: $durationMs,
                sourceLabel: $line,
            );

            if (! $track->isEmpty()) {
                $tracks[] = $track;
            }
        }

        return $tracks;
    }

    /**
     * Map each recognised field to its column index in this file's header.
     *
     * @param  array<int, string>  $header
     * @return array<string, int>
     */
    private function mapHeader(array $header): array
    {
        $normalised = array_map(fn (string $h): string => mb_strtolower(trim($h, " \t\"'")), $header);
        $map = [];

        foreach (self::COLUMNS as $field => $aliases) {
            foreach ($normalised as $index => $name) {
                if (in_array($name, $aliases, true)) {
                    $map[$field] = $index;

                    break;
                }
            }
        }

        return $map;
    }

    /**
     * Split one CSV row, honouring double-quoted cells that contain commas.
     *
     * @return array<int, string>
     */
    private function splitRow(string $line): array
    {
        // str_getcsv handles quoted commas and escaped quotes; tab-separated
        // files fall through to a single cell, which mapHeader then ignores.
        return str_getcsv($line);
    }

    /** Spotify ids arrive bare or as a `spotify:track:<id>` URI or an open URL. */
    private function spotifyId(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (preg_match('#(?:spotify:track:|open\.spotify\.com/track/)([A-Za-z0-9]+)#', $value, $m)) {
            return $m[1];
        }

        return preg_match('/^[A-Za-z0-9]{22}$/', $value) === 1 ? $value : null;
    }

    private function secondsToMs(string $value): ?int
    {
        // "3:45" or "225" or "225.0".
        if (preg_match('/^(\d+):(\d{1,2})$/', trim($value), $m)) {
            return ((int) $m[1] * 60 + (int) $m[2]) * 1000;
        }

        return is_numeric($value) ? (int) round((float) $value * 1000) : null;
    }

    private function clean(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim($value);

        // A multi-artist Spotify cell is "A, B" — keep the whole credit; matching
        // compares on the primary artist anyway.
        return $value === '' ? null : $value;
    }
}
