<?php

namespace App\Filament\Resources\Movies\Pages;

use App\Enums\ProcessingStatus;
use App\Filament\Resources\Movies\MovieResource;
use App\Jobs\EnrichMediaItemJob;
use App\Models\MediaItem;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditMovie extends EditRecord
{
    protected static string $resource = MovieResource::class;

    /** @var array<string, mixed> */
    protected array $metadataAttributes = [];

    protected function mutateFormDataBeforeFill(array $data): array
    {
        /** @var MediaItem $record */
        $record = $this->getRecord();

        $data['movieMetadata'] = $record->movieMetadata?->only([
            'director', 'studio', 'release_year', 'runtime_minutes',
            'tmdb_id', 'imdb_id', 'mpaa_rating', 'language',
        ]) ?? [];

        return $data;
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $this->metadataAttributes = $data['movieMetadata'] ?? [];
        unset($data['movieMetadata']);

        return $data;
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var MediaItem $record */
        $record->update($data);

        $record->movieMetadata()->updateOrCreate(
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

                    Notification::make()->title('Re-enrichment queued')->success()->send();
                }),
            DeleteAction::make(),
        ];
    }
}
