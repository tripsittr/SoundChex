<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Filament\Resources\Shows\Pages;

use App\Filament\Resources\Concerns\EditsMediaMetadata;
use App\Filament\Resources\Shows\ShowResource;
use Filament\Resources\Pages\EditRecord;

class EditShow extends EditRecord
{
    use EditsMediaMetadata;

    protected static string $resource = ShowResource::class;

    protected function metadataRelation(): string
    {
        return 'showMetadata';
    }

    /** @return array<int, string> */
    protected function metadataFields(): array
    {
        return [
            'creator', 'network', 'first_air_year', 'last_air_year',
            'season_count', 'episode_count', 'status', 'tmdb_id',
        ];
    }
}
