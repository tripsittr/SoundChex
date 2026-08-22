<?php

namespace App\Filament\Resources\Duplicates;

use App\Enums\DuplicateStatus;
use App\Models\MediaItem;
use App\Services\DuplicateDetector;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Collection;

/**
 * The duplicate review table.
 *
 * Every row shows both sides of the pair and whether they're two files or one
 * file catalogued twice — that distinction decides whether merging frees any
 * disk space, so it's shown rather than left for the user to infer.
 */
class DuplicatesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('duplicate_detected_at', 'desc')
            ->columns([
                TextColumn::make('title')
                    ->label('Duplicate')
                    ->searchable()
                    ->description(fn (MediaItem $record): string => static::shortPath($record)),

                TextColumn::make('duplicateOf.title')
                    ->label('Original')
                    ->placeholder('—')
                    ->description(fn (MediaItem $record): string => $record->duplicateOf
                        ? static::shortPath($record->duplicateOf)
                        : '—'),

                TextColumn::make('type')
                    ->badge()
                    ->toggleable(),

                // The important one: "same file" means there's nothing on disk
                // to reclaim, only a redundant catalog row.
                TextColumn::make('duplicate_kind')
                    ->label('Kind')
                    ->badge()
                    ->state(fn (MediaItem $record): string => static::isSharedFile($record)
                        ? 'Same file, two entries'
                        : 'Two copies on disk')
                    ->color(fn (MediaItem $record): string => static::isSharedFile($record)
                        ? 'gray'
                        : 'warning'),

                TextColumn::make('file_size')
                    ->label('Reclaims')
                    ->state(fn (MediaItem $record): string => static::reclaimable($record))
                    ->toggleable(),

                TextColumn::make('duplicate_status')
                    ->label('Status')
                    ->badge(),

                TextColumn::make('duplicate_detected_at')
                    ->label('Found')
                    ->since()
                    ->sortable()
                    ->toggleable(),

                TextColumn::make('content_hash')
                    ->label('Hash')
                    ->limit(12)
                    ->fontFamily('mono')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('duplicate_status')
                    ->label('Status')
                    ->options(collect(DuplicateStatus::cases())
                        ->mapWithKeys(fn (DuplicateStatus $case) => [$case->value => $case->getLabel()])
                        ->all())
                    ->default(DuplicateStatus::Pending->value),

                SelectFilter::make('type')
                    ->options([
                        'music' => 'Music',
                        'movie' => 'Movies',
                        'show'  => 'TV',
                        'book'  => 'Books',
                    ]),
            ])
            ->recordActions([
                static::mergeAction(),
                static::keepAction(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    static::mergeBulkAction(),
                    static::keepBulkAction(),
                ]),
            ])
            ->emptyStateHeading('No duplicates found')
            ->emptyStateDescription('Files are compared byte-for-byte as they are catalogued. Anything identical shows up here for review.');
    }

    /**
     * Merge: delete the redundant file and point this row at the surviving one.
     */
    private static function mergeAction(): Action
    {
        return Action::make('merge')
            ->label('Merge')
            ->icon('heroicon-o-arrows-pointing-in')
            ->color('danger')
            ->visible(fn (MediaItem $record): bool => $record->isPendingDuplicate())
            // This deletes a file, so it never happens on a single click.
            ->requiresConfirmation()
            ->modalHeading('Merge this duplicate?')
            ->modalDescription(fn (MediaItem $record): string => static::isSharedFile($record)
                ? 'Both entries point at the same file. The extra catalog entry is removed — the file itself is not touched.'
                : 'The duplicate file is deleted and this entry re-points at the original. Both files are compared byte-for-byte again first; if they differ, nothing is deleted.')
            ->modalSubmitActionLabel('Merge')
            ->action(function (MediaItem $record, DuplicateDetector $detector): void {
                if ($detector->merge($record)) {
                    Notification::make()
                        ->title('Merged')
                        ->success()
                        ->send();

                    return;
                }

                Notification::make()
                    ->title('Nothing was deleted')
                    ->body('The files are no longer identical, or the original is missing. This entry has been un-flagged so you can look at it.')
                    ->warning()
                    ->send();
            });
    }

    private static function keepAction(): Action
    {
        return Action::make('keep')
            ->label('Keep both')
            ->icon('heroicon-o-check')
            ->color('gray')
            ->visible(fn (MediaItem $record): bool => $record->isPendingDuplicate())
            ->action(function (MediaItem $record, DuplicateDetector $detector): void {
                $detector->keepBoth($record);

                Notification::make()
                    ->title('Keeping both copies')
                    ->body('This pair will not be flagged again.')
                    ->success()
                    ->send();
            });
    }

    private static function mergeBulkAction(): BulkAction
    {
        return BulkAction::make('mergeSelected')
            ->label('Merge selected')
            ->icon('heroicon-o-arrows-pointing-in')
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading('Merge the selected duplicates?')
            ->modalDescription('Each pair is compared byte-for-byte before anything is deleted. Any pair that no longer matches is skipped.')
            ->action(function (Collection $records, DuplicateDetector $detector): void {
                $merged = 0;
                $skipped = 0;

                foreach ($records as $record) {
                    if (! $record->isPendingDuplicate()) {
                        continue;
                    }

                    $detector->merge($record) ? $merged++ : $skipped++;
                }

                Notification::make()
                    ->title($merged . ' merged')
                    ->body($skipped > 0
                        ? $skipped . ' skipped — contents differ or the original is missing.'
                        : null)
                    ->success()
                    ->send();
            })
            ->deselectRecordsAfterCompletion();
    }

    private static function keepBulkAction(): BulkAction
    {
        return BulkAction::make('keepSelected')
            ->label('Keep both')
            ->icon('heroicon-o-check')
            ->color('gray')
            ->action(function (Collection $records, DuplicateDetector $detector): void {
                foreach ($records as $record) {
                    if ($record->isPendingDuplicate()) {
                        $detector->keepBoth($record);
                    }
                }

                Notification::make()
                    ->title('Keeping both copies')
                    ->success()
                    ->send();
            })
            ->deselectRecordsAfterCompletion();
    }

    /**
     * Whether both rows resolve to one file on disk.
     *
     * A re-import can catalogue the same path twice; merging that frees no
     * space and must not delete anything.
     */
    private static function isSharedFile(MediaItem $record): bool
    {
        $original = $record->duplicateOf;

        return $original !== null
            && $record->absoluteFilePath() === $original->absoluteFilePath();
    }

    private static function reclaimable(MediaItem $record): string
    {
        if (static::isSharedFile($record)) {
            return '—';
        }

        $path = $record->absoluteFilePath();

        if ($path === null || ! is_file($path)) {
            return '—';
        }

        return number_format(filesize($path) / 1048576, 1) . ' MB';
    }

    /** The last two path segments — enough to tell two files apart. */
    private static function shortPath(MediaItem $record): string
    {
        $path = $record->file_path;

        if (blank($path)) {
            return '—';
        }

        $parts = explode('/', str_replace('\\', '/', $path));

        return implode('/', array_slice($parts, -2));
    }
}
