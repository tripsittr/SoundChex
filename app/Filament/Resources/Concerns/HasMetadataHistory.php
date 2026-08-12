<?php

namespace App\Filament\Resources\Concerns;

use App\Models\MediaItem;
use App\Models\MetadataVersion;
use App\Services\MetadataHistory;
use Filament\Actions\Action;
use Filament\Notifications\Notification;

/**
 * The "History" table action, shared by every media type.
 *
 * Providers revise their own records, so a re-enrichment can replace a correct
 * value with a worse one. This is where that becomes visible and reversible.
 */
trait HasMetadataHistory
{
    protected static function historyAction(): Action
    {
        return Action::make('history')
            ->label(fn (MediaItem $record): string => ($count = $record->metadataVersions()->count()) > 0
                ? 'History (' . $count . ')'
                : 'History')
            ->icon('heroicon-o-clock')
            ->color('gray')
            ->visible(fn (MediaItem $record): bool => $record->metadataVersions()->exists())
            ->modalHeading(fn (MediaItem $record): string => 'Metadata history — ' . $record->title)
            ->modalDescription('Each entry is the state before that change. Restoring writes those values back; the current state is snapshotted first, so a restore is itself reversible.')
            ->modalContent(fn (MediaItem $record) => view(
                'filament.metadata-history',
                [
                    'record' => $record,
                    'versions' => $record->metadataVersions()->with('user')->limit(30)->get(),
                    'history' => app(MetadataHistory::class),
                ],
            ))
            // Read-only view; restoring is per-row inside the modal.
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Close');
    }

    /**
     * Restores one version. Registered separately so the modal's rows can
     * dispatch to it by id.
     */
    protected static function restoreVersionAction(): Action
    {
        return Action::make('restoreVersion')
            ->requiresConfirmation()
            ->modalHeading('Restore this version?')
            ->modalDescription('The current metadata is saved to history first, so this can be undone.')
            ->action(function (array $arguments, MediaItem $record): void {
                $version = MetadataVersion::find($arguments['version'] ?? null);

                if ($version === null || $version->media_item_id !== $record->id) {
                    Notification::make()->title('That version no longer exists')->danger()->send();

                    return;
                }

                app(MetadataHistory::class)->restore($record, $version);

                Notification::make()
                    ->title('Restored')
                    ->body('The previous values are back. The state before this restore is in the history.')
                    ->success()
                    ->send();
            });
    }
}
