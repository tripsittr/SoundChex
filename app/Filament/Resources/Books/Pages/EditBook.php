<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Filament\Resources\Books\Pages;

use App\Filament\Resources\Books\BookResource;
use App\Filament\Resources\Concerns\EditsMediaMetadata;
use Filament\Resources\Pages\EditRecord;

class EditBook extends EditRecord
{
    use EditsMediaMetadata;

    protected static string $resource = BookResource::class;

    protected function metadataRelation(): string
    {
        return 'bookMetadata';
    }

    /** @return array<int, string> */
    protected function metadataFields(): array
    {
        return [
            'author', 'publisher', 'publish_year', 'pages',
            'isbn_10', 'isbn_13', 'open_library_id',
            'series_name', 'series_position',
        ];
    }
}
