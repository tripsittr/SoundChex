<?php

namespace App\Filament\Resources\Books\Pages;

use App\Enums\ProcessingStatus;
use App\Filament\Resources\Books\BookResource;
use App\Jobs\EnrichMediaItemJob;
use App\Models\MediaItem;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditBook extends EditRecord
{
    protected static string $resource = BookResource::class;

    /** @var array<string, mixed> */
    protected array $metadataAttributes = [];

    protected function mutateFormDataBeforeFill(array $data): array
    {
        /** @var MediaItem $record */
        $record = $this->getRecord();

        $data['bookMetadata'] = $record->bookMetadata?->only([
            'author', 'publisher', 'publish_year', 'pages',
            'isbn_10', 'isbn_13', 'open_library_id',
            'series_name', 'series_position',
        ]) ?? [];

        return $data;
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $this->metadataAttributes = $data['bookMetadata'] ?? [];
        unset($data['bookMetadata']);

        return $data;
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var MediaItem $record */
        $record->update($data);

        $record->bookMetadata()->updateOrCreate(
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
