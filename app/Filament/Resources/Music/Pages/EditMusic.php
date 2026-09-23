<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Filament\Resources\Music\Pages;

use App\Filament\Resources\Concerns\EditsMediaMetadata;
use App\Filament\Resources\Music\MusicResource;
use Filament\Resources\Pages\EditRecord;

class EditMusic extends EditRecord
{
    use EditsMediaMetadata;

    protected static string $resource = MusicResource::class;

    protected function metadataRelation(): string
    {
        return 'musicMetadata';
    }

    /** @return array<int, string> */
    protected function metadataFields(): array
    {
        return [
            'artist', 'album', 'track_number', 'disc_number', 'release_year',
            'label', 'bpm', 'key', 'scale', 'isrc', 'musicbrainz_recording_id',
        ];
    }
}
