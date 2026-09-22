<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Services\Import\Sources;

/**
 * The streaming services playlists can be ported from (S-312).
 *
 * A small registry so the API and UI can list what is available and resolve one
 * by key. Connectors are added here as they are built; a plugin could contribute
 * more through a future seam, but the built-ins live here.
 */
class PlaylistSourceRegistry
{
    /**
     * @return array<int, PlaylistSource>
     */
    public function all(): array
    {
        return [
            app(SpotifySource::class),
            // AppleMusicSource, YouTubeMusicSource — added as they are built.
        ];
    }

    public function get(string $key): ?PlaylistSource
    {
        foreach ($this->all() as $source) {
            if ($source->key() === $key) {
                return $source;
            }
        }

        return null;
    }
}
