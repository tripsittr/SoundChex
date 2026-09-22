<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Jobs;

use App\Models\PlaylistImport;
use App\Services\CurrentProfile;
use App\Services\Import\ImportedTrack;
use App\Services\Import\PlaylistImportService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Ports a playlist in the background (S-310).
 *
 * Matching every track of a long playlist is too slow for a request, so the
 * parse result is handed here. The tracks are passed as plain arrays (Queueable
 * payload must serialise), rebuilt into descriptors in the worker.
 *
 * The importing profile is carried and restored, so track matching applies the
 * same rating cap it would in the request the import was started from — a queue
 * worker has no current profile of its own.
 */
class ImportPlaylistJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 600;

    /**
     * @param  array<int, array<string, mixed>>  $trackData
     */
    public function __construct(
        public readonly int $importId,
        public readonly array $trackData,
        public readonly string $playlistName,
        public readonly ?int $profileId,
    ) {
        $this->onQueue('import');
    }

    public function handle(PlaylistImportService $service, CurrentProfile $profiles): void
    {
        $import = PlaylistImport::find($this->importId);

        if ($import === null) {
            return;
        }

        // Scope matching to the profile the import was started as.
        if ($this->profileId !== null) {
            $profiles->switchTo($this->profileId);
        }

        $tracks = array_map($this->toTrack(...), $this->trackData);

        $service->run($import, $tracks, $this->playlistName);
    }

    public function failed(\Throwable $e): void
    {
        Log::error('playlist-import:job-failed', ['import' => $this->importId, 'error' => $e->getMessage()]);

        PlaylistImport::where('id', $this->importId)->update([
            'status' => PlaylistImport::STATUS_FAILED,
            'error' => $e->getMessage(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function toTrack(array $data): ImportedTrack
    {
        return new ImportedTrack(
            title: $data['title'] ?? null,
            artist: $data['artist'] ?? null,
            album: $data['album'] ?? null,
            isrc: $data['isrc'] ?? null,
            musicbrainzRecordingId: $data['musicbrainz_recording_id'] ?? null,
            spotifyId: $data['spotify_id'] ?? null,
            durationMs: isset($data['duration_ms']) ? (int) $data['duration_ms'] : null,
            sourceLabel: $data['source_label'] ?? null,
        );
    }
}
