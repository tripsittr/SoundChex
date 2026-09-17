<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Filament\Resources\Books\Pages;

use App\Filament\Resources\Books\BookResource;
use App\Jobs\EnrichMediaItemJob;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class CreateBook extends CreateRecord
{
    protected static string $resource = BookResource::class;

    /**
     * Nested `bookMetadata.*` fields can't be mass-assigned onto MediaItem, so
     * they're split out here and reattached once the parent row exists.
     *
     * @var array<string, mixed>
     */
    protected array $metadataAttributes = [];

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $this->metadataAttributes = $data['bookMetadata'] ?? [];
        unset($data['bookMetadata']);

        // Scanners and copy-paste bring hyphens and spaces along; the lookup
        // needs bare digits.
        if (filled($this->metadataAttributes['isbn_13'] ?? null)) {
            $this->metadataAttributes['isbn_13'] = preg_replace(
                '/[^0-9Xx]/',
                '',
                $this->metadataAttributes['isbn_13'],
            );
        }

        return BookResource::prepareCreateData($data, Auth::id());
    }

    protected function handleRecordCreation(array $data): Model
    {
        return BookResource::createWithMetadata($data, $this->metadataAttributes);
    }

    protected function afterCreate(): void
    {
        EnrichMediaItemJob::dispatch($this->record->id);
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
