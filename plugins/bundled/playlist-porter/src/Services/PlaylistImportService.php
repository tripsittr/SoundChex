<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace SoundChex\PlaylistPorter\Services;

use App\Models\Collection;
use Illuminate\Support\Facades\Log;
use SoundChex\PlaylistPorter\Models\PlaylistImport;
use SoundChex\PlaylistPorter\Services\Parsers\CsvParser;
use SoundChex\PlaylistPorter\Services\Parsers\M3uParser;
use SoundChex\PlaylistPorter\Services\Parsers\PlaylistFileParser;
use SoundChex\PlaylistPorter\Services\Parsers\XspfParser;

/**
 * Ports a playlist into the library (S-310).
 *
 * Parses a source into normalised tracks, matches each to a library item, builds
 * a playlist from the ones that matched, and records the rest as unmatched for
 * the user to resolve. The heavy lifting the clients drive: every platform only
 * has to hand this a file (or, later, a connected source) and show the result.
 */
class PlaylistImportService
{
    public function __construct(private TrackMatcher $matcher) {}

    /**
     * The file parsers, most specific first — XSPF and CSV are recognised by
     * extension and shape before the permissive M3U fallback.
     *
     * @return array<int, PlaylistFileParser>
     */
    private function fileParsers(): array
    {
        return [
            app(XspfParser::class),
            app(CsvParser::class),
            app(M3uParser::class),
        ];
    }

    /**
     * Reads a playlist file into a name and ordered tracks, or throws when no
     * parser recognises it.
     *
     * @return array{name: ?string, tracks: array<int, ImportedTrack>, format: string}
     */
    public function parseFile(string $contents, string $extension): array
    {
        foreach ($this->fileParsers() as $parser) {
            if ($parser->supports($extension, $contents)) {
                return [
                    'name' => $parser->name($contents),
                    'tracks' => $parser->parse($contents),
                    'format' => $this->formatOf($parser),
                ];
            }
        }

        throw new \RuntimeException('Unrecognised playlist file. Supported: M3U, CSV, XSPF.');
    }

    /**
     * Runs a prepared import: matches its tracks, creates the playlist, and
     * records the outcome. The tracks are passed in (already parsed) so this is
     * the same whether they came from a file or, later, a service.
     *
     * @param  array<int, ImportedTrack>  $tracks
     */
    public function run(PlaylistImport $import, array $tracks, string $playlistName): void
    {
        $import->update([
            'status' => PlaylistImport::STATUS_PROCESSING,
            'total_tracks' => count($tracks),
        ]);

        try {
            $collection = Collection::create([
                'user_id' => $import->user_id,
                'name' => $playlistName !== '' ? $playlistName : 'Imported playlist',
                'description' => 'Ported from '.($import->source_format ?? $import->source).'.',
            ]);

            $matched = 0;
            $unmatched = [];
            $sortOrder = 0;

            foreach ($tracks as $track) {
                $item = $this->matcher->match($track);

                if ($item === null) {
                    $unmatched[] = $track->toArray();

                    continue;
                }

                // Attach in playlist order, never detaching what is already there,
                // and skipping a track the playlist already holds (a playlist can
                // list the same song twice; the library item is attached once).
                $collection->mediaItems()->syncWithoutDetaching([
                    $item->id => ['sort_order' => $sortOrder++],
                ]);
                $matched++;
            }

            $import->update([
                'status' => PlaylistImport::STATUS_COMPLETE,
                'matched_tracks' => $matched,
                'unmatched' => $unmatched,
                'collection_id' => $collection->id,
            ]);
        } catch (\Throwable $e) {
            Log::error('playlist-import:failed', ['import' => $import->id, 'error' => $e->getMessage()]);
            $import->update([
                'status' => PlaylistImport::STATUS_FAILED,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function formatOf(PlaylistFileParser $parser): string
    {
        return match ($parser::class) {
            XspfParser::class => 'xspf',
            CsvParser::class => 'csv',
            M3uParser::class => 'm3u',
            default => 'file',
        };
    }
}
