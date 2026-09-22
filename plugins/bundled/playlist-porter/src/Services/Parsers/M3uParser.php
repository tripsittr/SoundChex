<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace SoundChex\PlaylistPorter\Services\Parsers;

use SoundChex\PlaylistPorter\Services\ImportedTrack;

/**
 * Parses M3U / M3U8 playlists (S-310).
 *
 * The de-facto portable playlist format. An `#EXTINF` line carries the duration
 * and a "Artist - Title" label; the following non-comment line is the path or
 * URL. We read the label (and duration) rather than the path, since the path is
 * the *source's* filesystem and means nothing here — the track is matched to the
 * library by its artist and title.
 */
class M3uParser implements PlaylistFileParser
{
    public function supports(string $extension, string $contents): bool
    {
        return in_array(strtolower($extension), ['m3u', 'm3u8'], true)
            || str_starts_with(ltrim($contents), '#EXTM3U');
    }

    public function name(string $contents): ?string
    {
        // #PLAYLIST:Name is an optional extension some exporters write.
        if (preg_match('/^#PLAYLIST:\s*(.+)$/mi', $contents, $m)) {
            return trim($m[1]);
        }

        return null;
    }

    public function parse(string $contents): array
    {
        $tracks = [];
        $pendingDuration = null;
        $pendingLabel = null;

        foreach (preg_split('/\r\n|\r|\n/', $contents) ?: [] as $line) {
            $line = trim($line);

            if ($line === '') {
                continue;
            }

            if (str_starts_with($line, '#EXTINF:')) {
                // #EXTINF:<seconds>,<Artist> - <Title>
                $info = substr($line, strlen('#EXTINF:'));
                [$seconds, $label] = array_pad(explode(',', $info, 2), 2, '');
                $secondsValue = (int) trim($seconds);
                $pendingDuration = $secondsValue > 0 ? $secondsValue * 1000 : null;
                $pendingLabel = trim($label) !== '' ? trim($label) : null;

                continue;
            }

            if (str_starts_with($line, '#')) {
                // Any other directive — ignore.
                continue;
            }

            // A content line: the entry the pending #EXTINF described (or a bare
            // path with no metadata).
            [$artist, $title] = $this->splitLabel($pendingLabel ?? $this->labelFromPath($line));

            $tracks[] = new ImportedTrack(
                title: $title,
                artist: $artist,
                durationMs: $pendingDuration,
                sourceLabel: $pendingLabel ?? $line,
            );

            $pendingDuration = null;
            $pendingLabel = null;
        }

        return $tracks;
    }

    /**
     * Split an "Artist - Title" label. When there is no separator, the whole
     * thing is the title (better to match on a title than to guess an artist).
     *
     * @return array{0: ?string, 1: ?string}  [artist, title]
     */
    private function splitLabel(?string $label): array
    {
        if ($label === null || $label === '') {
            return [null, null];
        }

        // " - " with surrounding spaces is the artist/title separator; a bare
        // hyphen inside a title ("Would?-Live") is not.
        if (preg_match('/^(.*?)\s+-\s+(.*)$/', $label, $m)) {
            return [trim($m[1]), trim($m[2])];
        }

        return [null, $label];
    }

    /** The filename (no extension) as a last-resort label for a bare path. */
    private function labelFromPath(string $path): string
    {
        $base = pathinfo(str_replace('\\', '/', $path), PATHINFO_FILENAME);

        return str_replace('_', ' ', $base);
    }
}
