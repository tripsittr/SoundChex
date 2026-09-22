<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Services\Import\Parsers;

use App\Services\Import\ImportedTrack;

/**
 * Parses XSPF (XML Shareable Playlist Format) playlists (S-310).
 *
 * The open XML playlist standard — `<track>` elements with `<title>`,
 * `<creator>` (artist), `<album>`, and `<duration>` in milliseconds. Read with
 * PHP's built-in SimpleXML, so no dependency is added.
 */
class XspfParser implements PlaylistFileParser
{
    public function supports(string $extension, string $contents): bool
    {
        return strtolower($extension) === 'xspf'
            || str_contains($contents, '<playlist') && str_contains($contents, 'xspf');
    }

    public function name(string $contents): ?string
    {
        $xml = $this->load($contents);

        if ($xml === null) {
            return null;
        }

        $title = trim((string) ($xml->title ?? ''));

        return $title !== '' ? $title : null;
    }

    public function parse(string $contents): array
    {
        $xml = $this->load($contents);

        if ($xml === null || ! isset($xml->trackList->track)) {
            return [];
        }

        $tracks = [];

        foreach ($xml->trackList->track as $node) {
            $duration = (int) trim((string) ($node->duration ?? ''));

            $track = new ImportedTrack(
                title: $this->text($node->title ?? null),
                artist: $this->text($node->creator ?? null),
                album: $this->text($node->album ?? null),
                durationMs: $duration > 0 ? $duration : null,
                sourceLabel: $this->text($node->title ?? null) ?? trim((string) ($node->location ?? '')),
            );

            if (! $track->isEmpty()) {
                $tracks[] = $track;
            }
        }

        return $tracks;
    }

    private function load(string $contents): ?\SimpleXMLElement
    {
        // Guard against XML external entity attacks and malformed input: no
        // network/entity loading, and libxml errors captured rather than warned.
        $previous = libxml_use_internal_errors(true);

        try {
            $xml = simplexml_load_string($contents, \SimpleXMLElement::class, LIBXML_NONET);

            return $xml === false ? null : $xml;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    private function text(?\SimpleXMLElement $node): ?string
    {
        if ($node === null) {
            return null;
        }

        $value = trim((string) $node);

        return $value === '' ? null : $value;
    }
}
