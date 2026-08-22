<?php

namespace App\Filament\Resources\Music\Pages;

use App\Enums\MediaItemType;
use App\Enums\ProcessingStatus;
use App\Filament\Resources\Music\MusicResource;
use App\Jobs\EnrichMediaItemJob;
use App\Models\MediaItem;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class CreateMusic extends CreateRecord
{
    protected static string $resource = MusicResource::class;

    /**
     * Nested musicMetadata.* fields can't be mass-assigned onto MediaItem.
     * Split them out here and reattach after the parent row exists.
     *
     * @var array<string, mixed>
     */
    protected array $metadataAttributes = [];

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $this->metadataAttributes = $data['musicMetadata'] ?? [];
        unset($data['musicMetadata']);

        $data['type'] = MediaItemType::Music;
        $data['user_id'] = Auth::id();
        $data['processing_status'] = ProcessingStatus::Pending;

        // A file upload with no title yet falls back to the filename; FileTagger
        // promotes the real title once tags are read.
        if (blank($data['title'] ?? null) && filled($data['file_path'] ?? null)) {
            $data['title'] = pathinfo($data['file_path'], PATHINFO_FILENAME);
        }

        return $data;
    }

    protected function handleRecordCreation(array $data): Model
    {
        /** @var MediaItem $record */
        $record = static::getModel()::create($data);

        $filled = array_filter($this->metadataAttributes, fn ($v) => filled($v));

        // Always create the metadata row, even when empty — the pipeline writes
        // into it and FileTagger expects somewhere to put what it finds.
        $record->musicMetadata()->create($filled);

        return $record;
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
