<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Filament\Resources\Music\Pages;

use App\Enums\ProcessingStatus;
use App\Filament\Resources\Music\MusicResource;
use App\Jobs\EnrichMediaItemJob;
use App\Models\MediaItem;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditMusic extends EditRecord
{
    protected static string $resource = MusicResource::class;

    /**
     * @var array<string, mixed>
     */
    protected array $metadataAttributes = [];

    /**
     * Flattens the related metadata row into the `musicMetadata.*` form fields.
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        /** @var MediaItem $record */
        $record = $this->getRecord();

        $data['musicMetadata'] = $record->musicMetadata?->only([
            'artist', 'album', 'track_number', 'disc_number', 'release_year',
            'label', 'bpm', 'key', 'scale', 'isrc', 'musicbrainz_recording_id',
        ]) ?? [];

        return $data;
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $this->metadataAttributes = $data['musicMetadata'] ?? [];
        unset($data['musicMetadata']);

        return $data;
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var MediaItem $record */
        $record->update($data);

        $record->musicMetadata()->updateOrCreate(
            ['media_item_id' => $record->id],
            $this->metadataAttributes,
        );

        return $record;
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('reenrich')
                ->label('Re-enrich')
                ->icon('heroicon-o-arrow-path')
                ->color('gray')
                ->requiresConfirmation()
                ->modalDescription('Re-runs the metadata pipeline. Values already set are kept.')
                ->action(function (): void {
                    $this->record->update(['processing_status' => ProcessingStatus::Pending]);
                    EnrichMediaItemJob::dispatch($this->record->id);

                    Notification::make()
                        ->title('Re-enrichment queued')
                        ->success()
                        ->send();
                }),
            DeleteAction::make(),
        ];
    }
}
