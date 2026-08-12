<?php

namespace App\Filament\Resources\Movies\Pages;

use App\Filament\Resources\Movies\MovieResource;
use App\Jobs\EnrichMediaItemJob;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class CreateMovie extends CreateRecord
{
    protected static string $resource = MovieResource::class;

    /**
     * Nested `movieMetadata.*` fields can't be mass-assigned onto MediaItem, so
     * they're split out here and reattached once the parent row exists.
     *
     * @var array<string, mixed>
     */
    protected array $metadataAttributes = [];

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $this->metadataAttributes = $data['movieMetadata'] ?? [];
        unset($data['movieMetadata']);

        return MovieResource::prepareCreateData($data, Auth::id());
    }

    protected function handleRecordCreation(array $data): Model
    {
        return MovieResource::createWithMetadata($data, $this->metadataAttributes);
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
