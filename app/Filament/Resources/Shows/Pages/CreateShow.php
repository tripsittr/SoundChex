<?php

namespace App\Filament\Resources\Shows\Pages;

use App\Filament\Resources\Shows\ShowResource;
use App\Jobs\EnrichMediaItemJob;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class CreateShow extends CreateRecord
{
    protected static string $resource = ShowResource::class;

    /**
     * Nested `showMetadata.*` fields can't be mass-assigned onto MediaItem, so
     * they're split out here and reattached once the parent row exists.
     *
     * @var array<string, mixed>
     */
    protected array $metadataAttributes = [];

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $this->metadataAttributes = $data['showMetadata'] ?? [];
        unset($data['showMetadata']);

        return ShowResource::prepareCreateData($data, Auth::id());
    }

    protected function handleRecordCreation(array $data): Model
    {
        return ShowResource::createWithMetadata($data, $this->metadataAttributes);
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
