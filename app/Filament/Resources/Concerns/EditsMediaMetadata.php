<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Filament\Resources\Concerns;

use App\Enums\ProcessingStatus;
use App\Jobs\EnrichMediaItemJob;
use App\Models\MediaItem;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Model;

/**
 * The edit-page plumbing every media type shares.
 *
 * All four types are rows in `media_items` with a type-specific metadata row
 * beside them, so all four edit pages do the same three things: flatten that
 * row into `<relation>.*` form fields on fill, lift it back out before save,
 * and write it with `updateOrCreate` after the item itself. Only the relation
 * name and the editable field list differ, which is what the two abstract
 * methods supply.
 */
trait EditsMediaMetadata
{
    /**
     * The metadata relation this page edits — `musicMetadata`, `bookMetadata`,
     * and so on.
     */
    abstract protected function metadataRelation(): string;

    /**
     * The metadata columns this page's form exposes.
     *
     * Listed explicitly rather than taken from the model: the form decides what
     * is editable, and a column added to the table should not silently become a
     * writable field here.
     *
     * @return array<int, string>
     */
    abstract protected function metadataFields(): array;

    /**
     * The metadata values lifted out of the form data, held between
     * `mutateFormDataBeforeSave()` and `handleRecordUpdate()`.
     *
     * @var array<string, mixed>
     */
    protected array $metadataAttributes = [];

    /**
     * Flattens the related metadata row into the `<relation>.*` form fields.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        /** @var MediaItem $record */
        $record = $this->getRecord();

        $relation = $this->metadataRelation();

        $data[$relation] = $record->{$relation}?->only($this->metadataFields()) ?? [];

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $relation = $this->metadataRelation();

        $this->metadataAttributes = $data[$relation] ?? [];
        unset($data[$relation]);

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var MediaItem $record */
        $record->update($data);

        $record->{$this->metadataRelation()}()->updateOrCreate(
            ['media_item_id' => $record->id],
            $this->metadataAttributes,
        );

        return $record;
    }

    /**
     * @return array<int, Action|DeleteAction>
     */
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

                    Notification::make()->title('Re-enrichment queued')->success()->send();
                }),
            DeleteAction::make(),
        ];
    }
}
