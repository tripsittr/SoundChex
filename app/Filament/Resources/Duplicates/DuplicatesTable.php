<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Filament\Resources\Duplicates;

use App\Jobs\RefetchCoversJob;
use App\Models\MediaItem;
use App\Services\DuplicateDetector;
use App\Services\Metadata\CoverArtFetcher;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Forms\Components\Radio;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\ImageColumn;
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
        // On the cover-review tab, lay the rows out as a grid of large cover
        // cards to approve or refetch — far easier than scanning a table of
        // 44px thumbnails. Other tabs keep the table.
        $onCoverTab = static::isCoverTab($table->getLivewire());

        return $table
            ->defaultSort('duplicate_detected_at', 'desc')
            ->when($onCoverTab, fn (Table $t) => $t->contentGrid([
                'md' => 2,
                'xl' => 3,
                '2xl' => 4,
            ]))
            ->columns([
                // The kept copy's cover. On the cover grid it is the big card
                // image; in the table it is a small thumbnail. Resolved through
                // coverUrl() (the stored value is a public-disk path with spaces,
                // not a ready URL), or a raw ImageColumn renders many blank.
                ImageColumn::make('cover_image_url')
                    ->label('Cover')
                    ->square()
                    ->size($onCoverTab ? 220 : 56)
                    ->getStateUsing(fn (MediaItem $record): ?string => $record->coverUrl())
                    ->defaultImageUrl('https://placehold.co/220x220/1f2937/6b7280?text=%3F')
                    ->extraImgAttributes($onCoverTab ? ['class' => 'w-full rounded-lg'] : [])
                    ->toggleable(! $onCoverTab),

                TextColumn::make('title')
                    ->label($onCoverTab ? 'Track' : 'Duplicate')
                    ->searchable()
                    ->weight($onCoverTab ? 'medium' : null)
                    // On the cover grid, show artist · album under the title so
                    // the reviewer can judge whether the cover matches. In the
                    // table, the file path is more useful.
                    ->description(fn (MediaItem $record): string => $onCoverTab
                        ? static::trackLine($record)
                        : static::shortPath($record)),

                TextColumn::make('duplicateOf.title')
                    ->label('Original')
                    ->placeholder('—')
                    ->visible(! $onCoverTab)
                    ->description(fn (MediaItem $record): string => $record->duplicateOf
                        ? static::shortPath($record->duplicateOf)
                        : '—'),

                TextColumn::make('type')
                    ->badge()
                    ->visible(! $onCoverTab)
                    ->toggleable(),

                // Why the pair was flagged — an identical file, or the same
                // recording in a different file (and how sure we are of that).
                TextColumn::make('duplicate_match')
                    ->label('Match')
                    ->badge()
                    ->placeholder('Identical file')
                    ->visible(! $onCoverTab)
                    ->toggleable(),

                // The important one: "same file" means there's nothing on disk
                // to reclaim, only a redundant catalog row.
                TextColumn::make('duplicate_kind')
                    ->label('Kind')
                    ->badge()
                    ->visible(! $onCoverTab)
                    ->state(fn (MediaItem $record): string => static::isSharedFile($record)
                        ? 'Same file, two entries'
                        : 'Two copies on disk')
                    ->color(fn (MediaItem $record): string => static::isSharedFile($record)
                        ? 'gray'
                        : 'warning'),

                TextColumn::make('file_size')
                    ->label('Reclaims')
                    ->state(fn (MediaItem $record): string => static::reclaimable($record))
                    ->visible(! $onCoverTab)
                    ->toggleable(),

                TextColumn::make('duplicate_status')
                    ->label('Status')
                    ->badge()
                    ->visible(! $onCoverTab),

                TextColumn::make('duplicate_detected_at')
                    ->label('Found')
                    ->since()
                    ->sortable()
                    ->visible(! $onCoverTab)
                    ->toggleable(),

                TextColumn::make('content_hash')
                    ->label('Hash')
                    ->limit(12)
                    ->fontFamily('mono')
                    ->visible(! $onCoverTab)
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            // Status is chosen by the tabs above the table (Needs review / Merged
            // / Verify cover art / …). A status *filter* here as well stacked with
            // the tab — the Merged tab plus a filter still defaulting to Pending
            // resolved to "merged AND pending", i.e. nothing, so the tab read 0.
            // Type is the only filter that belongs here.
            ->filters([
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
                static::coverVerifiedAction(),
                static::refetchCoverAction(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    static::mergeBulkAction(),
                    static::keepBulkAction(),
                    static::coverVerifiedBulkAction(),
                    static::refetchCoverBulkAction(),
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

    /**
     * Marks the kept cover as verified, clearing it from the cover-review tab.
     * Shown only on a row that is actually flagged for cover review (S-265).
     */
    private static function coverVerifiedAction(): Action
    {
        return Action::make('coverVerified')
            ->label('Cover is fine')
            ->icon('heroicon-o-photo')
            ->color('info')
            ->visible(fn (MediaItem $record): bool => (bool) $record->needs_cover_review)
            ->action(function (MediaItem $record, DuplicateDetector $detector): void {
                $detector->clearCoverReview($record);

                Notification::make()
                    ->title('Cover verified')
                    ->body('Cleared from the cover-review list.')
                    ->success()
                    ->send();
            });
    }

    /** Per-row: refetch a verified cover for this track and clear the flag. */
    private static function refetchCoverAction(): Action
    {
        return Action::make('refetchCover')
            ->label('Refetch')
            ->icon('heroicon-o-arrow-down-tray')
            ->color('warning')
            ->visible(fn (MediaItem $record): bool => (bool) $record->needs_cover_review)
            ->action(function (MediaItem $record, CoverArtFetcher $fetcher, DuplicateDetector $detector): void {
                $cover = $fetcher->fetchForAlbum($record->musicMetadata?->artist, $record->musicMetadata?->album);

                if ($cover !== null) {
                    $record->forceFill(['cover_image_url' => $cover])->saveQuietly();
                }

                $detector->clearCoverReview($record);

                Notification::make()
                    ->title($cover !== null ? 'Cover refetched' : 'No confident match')
                    ->body($cover !== null
                        ? 'Replaced with a verified album cover.'
                        : 'Kept the current cover; cleared from review.')
                    ->success()
                    ->send();
            });
    }

    /**
     * The one smart bulk action: resolve every selected pair by deleting the copy
     * that should go, and keeping the one that should stay.
     *
     *  - Byte-identical → delete the redundant copy (byte-re-compared first).
     *  - Same recording, different file → keep the higher-quality copy (higher
     *    bitrate, then sample rate, then more complete tags); on a genuine tie,
     *    keep the newer copy — a re-download is usually the one meant.
     *
     * The confirmation modal previews exactly what will happen and why. Nothing
     * is deleted until confirmed.
     */
    private static function mergeBulkAction(): BulkAction
    {
        return BulkAction::make('mergeSelected')
            ->label('Merge selected')
            ->icon('heroicon-o-arrows-pointing-in')
            ->color('danger')
            // Merging acts on pending pairs — hidden on the cover tab, where the
            // rows are already merged and only the cover needs a look.
            ->visible(fn ($livewire): bool => static::tabHasPending($livewire))
            ->requiresConfirmation()
            ->modalHeading('Merge the selected duplicates?')
            ->modalDescription(fn (Collection $records): string => static::mergePreview($records))
            ->modalSubmitActionLabel('Merge and delete the extra copies')
            ->action(function (Collection $records, DuplicateDetector $detector): void {
                $merged = 0;
                $skipped = 0;

                foreach ($records as $record) {
                    if (! $record->isPendingDuplicate()) {
                        continue;
                    }

                    if (static::isContentMatch($record)) {
                        // Keep the better copy; break a true tie toward the newer.
                        $detector->resolveKeepingBest($record, breakTies: true) === 'resolved'
                            ? $merged++
                            : $skipped++;
                    } else {
                        $detector->merge($record) ? $merged++ : $skipped++;
                    }
                }

                Notification::make()
                    ->title($merged.' merged')
                    ->body($skipped > 0
                        ? $skipped.' skipped — a file was missing or the contents no longer match.'
                        : null)
                    ->success()
                    ->send();
            })
            ->deselectRecordsAfterCompletion();
    }

    /**
     * Preview of what "Merge selected" will do: how many byte-identical copies
     * are removed, how many content pairs keep the better (or newer) copy, and
     * roughly how much space is reclaimed.
     */
    private static function mergePreview(Collection $records): string
    {
        $detector = app(DuplicateDetector::class);
        $identical = 0;
        $quality = 0;
        $newer = 0;
        $reclaim = 0;

        foreach ($records as $record) {
            if (! $record->isPendingDuplicate()) {
                continue;
            }

            if (! static::isContentMatch($record)) {
                $identical++;
                $path = $record->absoluteFilePath();
                if ($path !== null && is_file($path)) {
                    $reclaim += filesize($path);
                }

                continue;
            }

            [$winner, $reason] = $detector->decideKeeper($record->duplicateOf, $record, breakTies: true);

            if ($winner === null) {
                continue;
            }

            $reason === 'newer' ? $newer++ : $quality++;

            $loser = $winner->is($record) ? $record->duplicateOf : $record;
            $path = $loser?->absoluteFilePath();
            if ($path !== null && is_file($path)) {
                $reclaim += filesize($path);
            }
        }

        $parts = [];
        if ($identical > 0) {
            $parts[] = $identical.' identical '.str('copy')->plural($identical).' removed';
        }
        if ($quality > 0) {
            $parts[] = $quality.' will keep the higher-quality copy';
        }
        if ($newer > 0) {
            $parts[] = $newer.' are an even match — the newer copy is kept';
        }

        $summary = $parts ? implode('; ', $parts) : 'Nothing to merge in the selection';
        if ($reclaim > 0) {
            $summary .= '. ~'.number_format($reclaim / 1048576, 0).' MB reclaimed';
        }

        return $summary.'. This deletes files and cannot be undone.';
    }

    private static function keepBulkAction(): BulkAction
    {
        return BulkAction::make('keepSelected')
            ->label('Keep both')
            ->icon('heroicon-o-check')
            ->color('gray')
            ->visible(fn ($livewire): bool => static::tabHasPending($livewire))
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
     * Bulk: clear the cover-review flag on the selected rows.
     *
     * The cover-review queue is per-row and can be long; this clears whatever the
     * user has eyeballed and judged fine in one action, rather than 200 clicks.
     */
    private static function coverVerifiedBulkAction(): BulkAction
    {
        return BulkAction::make('coverVerifiedSelected')
            ->label('Covers are fine')
            ->icon('heroicon-o-photo')
            ->color('info')
            // Only meaningful on the cover-review tab.
            ->visible(fn ($livewire): bool => static::isCoverTab($livewire))
            ->action(function (Collection $records, DuplicateDetector $detector): void {
                $cleared = 0;

                foreach ($records as $record) {
                    if ($record->needs_cover_review) {
                        $detector->clearCoverReview($record);
                        $cleared++;
                    }
                }

                Notification::make()
                    ->title($cleared.' cleared from cover review')
                    ->success()
                    ->send();
            })
            ->deselectRecordsAfterCompletion();
    }

    /**
     * Bulk: refetch a verified album cover for the selected rows and clear the
     * flag. Efficient — the fetcher looks each album up once and shares it.
     */
    private static function refetchCoverBulkAction(): BulkAction
    {
        return BulkAction::make('refetchCoverSelected')
            ->label('Refetch correct cover')
            ->icon('heroicon-o-arrow-down-tray')
            ->color('warning')
            ->visible(fn ($livewire): bool => static::isCoverTab($livewire))
            ->requiresConfirmation()
            ->modalHeading('Refetch covers for the selected tracks?')
            ->modalDescription('Looks each album up online (verified by artist) and replaces the cover in the background, then clears the review flag. Tracks with no confident match keep their current cover.')
            ->action(function (Collection $records): void {
                // Fetching is network-bound (one call per album, throttled), so a
                // bulk refetch cannot run in the web request without timing out —
                // it goes to the queue. The rows leave the review list as the job
                // clears each flag.
                $ids = $records
                    ->filter(fn (MediaItem $r): bool => (bool) $r->needs_cover_review)
                    ->pluck('id')
                    ->all();

                if ($ids === []) {
                    Notification::make()->title('Nothing to refetch')->warning()->send();

                    return;
                }

                RefetchCoversJob::dispatch($ids);

                Notification::make()
                    ->title('Refetching '.count($ids).' cover'.(count($ids) === 1 ? '' : 's'))
                    ->body('Running in the background. Refresh in a moment to see them update.')
                    ->success()
                    ->send();
            })
            ->deselectRecordsAfterCompletion();
    }

    /** The list page's currently-selected tab key ('pending', 'cover', …). */
    private static function activeTab($livewire): ?string
    {
        // $livewire is the ListRecords page (which has $activeTab) in normal use,
        // but be defensive: it can be null or another component in some contexts.
        return is_object($livewire) && property_exists($livewire, 'activeTab')
            ? $livewire->activeTab
            : null;
    }

    /** The cover-review tab, where rows are already merged and only art is checked. */
    private static function isCoverTab($livewire): bool
    {
        return static::activeTab($livewire) === 'cover';
    }

    /** A tab that can contain pending pairs to merge (not the cover-only tab). */
    private static function tabHasPending($livewire): bool
    {
        return static::activeTab($livewire) !== 'cover';
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

    /** "Artist · Album" for the cover grid, skipping missing parts. */
    private static function trackLine(MediaItem $record): string
    {
        $meta = $record->musicMetadata;

        $parts = array_filter([$meta?->artist, $meta?->album]);

        return $parts === [] ? '—' : implode(' · ', $parts);
    }
}
