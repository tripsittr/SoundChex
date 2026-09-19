<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Filament\Resources\Duplicates;

use App\Enums\DuplicateStatus;
use App\Models\MediaItem;
use App\Services\DuplicateDetector;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Forms\Components\Radio;
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

                // Why the pair was flagged — an identical file, or the same
                // recording in a different file (and how sure we are of that).
                TextColumn::make('duplicate_match')
                    ->label('Match')
                    ->badge()
                    ->placeholder('Identical file')
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
                        'show' => 'TV',
                        'book' => 'Books',
                    ]),
            ])
            ->recordActions([
                static::mergeAction(),
                static::resolveContentAction(),
                static::keepAction(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    static::mergeBulkAction(),
                    static::resolveBestBulkAction(),
                    static::keepBulkAction(),
                ]),
            ])
            ->emptyStateHeading('No duplicates found')
            ->emptyStateDescription('Files are checked as they are catalogued — byte-for-byte, and (for music) for the same recording in a different file. Anything matching shows up here for review.');
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
            // Byte-identical copies only. A content match's files differ, so it
            // is resolved by choosing which to keep (resolveContentAction).
            ->visible(fn (MediaItem $record): bool => $record->isPendingDuplicate()
                && ! static::isContentMatch($record))
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

    /**
     * Resolve a content match (same recording, different file) by choosing which
     * copy to keep. The other file is deleted — there is no byte re-compare,
     * because the two files differ by definition; the user's choice is the gate.
     */
    private static function resolveContentAction(): Action
    {
        return Action::make('resolveContent')
            ->label('Keep one')
            ->icon('heroicon-o-scale')
            ->color('danger')
            ->visible(fn (MediaItem $record): bool => $record->isPendingDuplicate()
                && static::isContentMatch($record))
            ->schema([
                Radio::make('keep')
                    ->label('Which copy do you want to keep?')
                    ->options(fn (MediaItem $record): array => [
                        'original' => 'Keep: '.static::shortPath($record->duplicateOf).static::sizeSuffix($record->duplicateOf),
                        'duplicate' => 'Keep: '.static::shortPath($record).static::sizeSuffix($record),
                    ])
                    ->default('original')
                    ->required(),
            ])
            ->requiresConfirmation()
            ->modalHeading('Keep one copy, delete the other')
            ->modalDescription('These are the same recording in two different files. The copy you do not keep is deleted from disk. This cannot be undone.')
            ->modalSubmitActionLabel('Delete the other copy')
            ->action(function (MediaItem $record, array $data, DuplicateDetector $detector): void {
                $keepDuplicate = ($data['keep'] ?? 'original') === 'duplicate';

                if ($detector->resolveKeeping($record, keepDuplicate: $keepDuplicate)) {
                    Notification::make()
                        ->title('Resolved')
                        ->body('Kept one copy and deleted the other.')
                        ->success()
                        ->send();

                    return;
                }

                Notification::make()
                    ->title('Nothing was deleted')
                    ->body('The copy to keep is missing, so the other was left in place. This entry has been left for you to look at.')
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
            ->modalDescription('Only byte-identical copies are merged, each re-compared byte-for-byte first. Same-recording matches (different files) are skipped — resolve those one at a time with “Keep one”, since which copy to keep is your choice.')
            ->action(function (Collection $records, DuplicateDetector $detector): void {
                $merged = 0;
                $skipped = 0;
                $content = 0;

                foreach ($records as $record) {
                    if (! $record->isPendingDuplicate()) {
                        continue;
                    }

                    // Content matches are never bulk-deleted — the keeper is a
                    // per-pair choice made in the single-row action.
                    if (static::isContentMatch($record)) {
                        $content++;

                        continue;
                    }

                    $detector->merge($record) ? $merged++ : $skipped++;
                }

                $notes = [];
                if ($skipped > 0) {
                    $notes[] = $skipped.' skipped — contents differ or the original is missing.';
                }
                if ($content > 0) {
                    $notes[] = $content.' same-recording match'.($content === 1 ? '' : 'es').' left for you to resolve with “Keep one”.';
                }

                Notification::make()
                    ->title($merged.' merged')
                    ->body($notes ? implode(' ', $notes) : null)
                    ->success()
                    ->send();
            })
            ->deselectRecordsAfterCompletion();
    }

    /**
     * Bulk: for each selected content pair, keep the higher-quality copy (higher
     * bitrate, then sample rate, then tag completeness) and delete the other.
     * Pairs where neither copy is clearly better are left for review, and the
     * confirmation modal previews exactly how many will be resolved, skipped as a
     * tie, and how much space is reclaimed — nothing is deleted until confirmed.
     */
    private static function resolveBestBulkAction(): BulkAction
    {
        return BulkAction::make('resolveBestSelected')
            ->label('Keep the best copy')
            ->icon('heroicon-o-sparkles')
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading('Keep the best copy of each?')
            ->modalDescription(fn (Collection $records): string => static::bestPreview($records))
            ->modalSubmitActionLabel('Delete the lesser copies')
            ->action(function (Collection $records, DuplicateDetector $detector): void {
                $resolved = 0;
                $ties = 0;
                $skipped = 0;

                foreach ($records as $record) {
                    if (! $record->isPendingDuplicate() || ! static::isContentMatch($record)) {
                        $skipped++;

                        continue;
                    }

                    match ($detector->resolveKeepingBest($record)) {
                        'resolved' => $resolved++,
                        'tie' => $ties++,
                        default => $skipped++,
                    };
                }

                $notes = [];
                if ($ties > 0) {
                    $notes[] = $ties.' left for review — the two copies are too close to call.';
                }
                if ($skipped > 0) {
                    $notes[] = $skipped.' skipped (not a same-recording match, or a file was missing).';
                }

                Notification::make()
                    ->title($resolved.' resolved — best copy kept')
                    ->body($notes ? implode(' ', $notes) : null)
                    ->success()
                    ->send();
            })
            ->deselectRecordsAfterCompletion();
    }

    /**
     * A one-line preview of what "Keep the best copy" would do to the selection.
     */
    private static function bestPreview(Collection $records): string
    {
        $detector = app(DuplicateDetector::class);
        $resolve = 0;
        $ties = 0;
        $other = 0;
        $reclaim = 0;

        foreach ($records as $record) {
            if (! $record->isPendingDuplicate() || ! static::isContentMatch($record)) {
                $other++;

                continue;
            }

            $winner = $detector->bestCopy($record->duplicateOf, $record);

            if ($winner === null) {
                $ties++;

                continue;
            }

            $resolve++;
            // The copy being deleted is the one that is not the winner.
            $loser = $winner->is($record) ? $record->duplicateOf : $record;
            $path = $loser?->absoluteFilePath();
            if ($path !== null && is_file($path)) {
                $reclaim += filesize($path);
            }
        }

        $parts = [$resolve.' of '.$records->count().' will keep the higher-quality copy and delete the other'];
        if ($reclaim > 0) {
            $parts[0] .= ' (~'.number_format($reclaim / 1048576, 0).' MB reclaimed)';
        }
        if ($ties > 0) {
            $parts[] = $ties.' too close to call will be left for review';
        }
        if ($other > 0) {
            $parts[] = $other.' are not same-recording matches and will be skipped';
        }

        return implode('. ', $parts).'. This deletes files and cannot be undone.';
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

    /**
     * Whether this pair is a content match (same recording, different file)
     * rather than a byte-identical copy. A null match is an old byte-only row.
     */
    private static function isContentMatch(MediaItem $record): bool
    {
        return $record->duplicate_match?->isContent() === true;
    }

    /** " · 4.2 MB" for a copy, when its file is on disk; empty otherwise. */
    private static function sizeSuffix(?MediaItem $record): string
    {
        $path = $record?->absoluteFilePath();

        if ($path === null || ! is_file($path)) {
            return '';
        }

        return ' · '.number_format(filesize($path) / 1048576, 1).' MB';
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

        return number_format(filesize($path) / 1048576, 1).' MB';
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
