<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Filament\Resources\Movies\Pages;

use App\Filament\Resources\Concerns\EditsMediaMetadata;
use App\Filament\Resources\Movies\MovieResource;
use Filament\Resources\Pages\EditRecord;

class EditMovie extends EditRecord
{
    use EditsMediaMetadata;

    protected static string $resource = MovieResource::class;

    protected function metadataRelation(): string
    {
        return 'movieMetadata';
    }

    /** @return array<int, string> */
    protected function metadataFields(): array
    {
        return [
            'director', 'studio', 'release_year', 'runtime_minutes',
            'tmdb_id', 'imdb_id', 'mpaa_rating', 'language',
        ];
    }
}
