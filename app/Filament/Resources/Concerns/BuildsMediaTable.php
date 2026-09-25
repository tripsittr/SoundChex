<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Filament\Resources\Concerns;

use App\Enums\MediaItemType;
use App\Enums\ProcessingStatus;
use App\Jobs\EnrichMediaItemJob;
use App\Jobs\TranscodeMediaJob;
use App\Models\MediaItem;
use App\Services\MediaTranscoder;
use App\Services\Subtitles\SubtitleImporter;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\Column;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * The columns, filters, and actions every media table shares.
 *
 * Type-specific columns get spliced in via `$extraColumns`, so a movie table
 * can show runtime and a book table page count without either restating
 * artwork, status, rating, or the re-enrich actions.
 */
trait BuildsMediaTable
{
    use HasMetadataHistory;

    /**
     * @param  array<int, Column>  $extraColumns
     */
    protected static function baseTable(Table $table, array $extraColumns = [], string $placeholder = '🎬'): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            // Encoding runs for tens of minutes, so the percentage has to move
            // on its own — a static number is indistinguishable from a stalled
            // job. Only polls while something is actually converting, so an
            // idle table doesn't query on a timer for no reason.
            ->poll(static::hasActiveTranscode() ? '10s' : null)
            ->columns([
                ImageColumn::make('cover_image_url')
                    ->label('')
                    ->square()
                    ->defaultImageUrl(fn (): string => 'https://placehold.co/72x108/1f2937/6b7280?text='
                        .rawurlencode($placeholder)),

                TextColumn::make('title')
                    ->searchable()
                    ->sortable()
                    ->weight('medium')
                    ->description(fn (MediaItem $record): ?string => $record->subtitle()),

                ...$extraColumns,

                TextColumn::make('tags.value')
                    ->label('Genres')
                    ->badge()
                    ->limitList(2)
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('processing_status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (ProcessingStatus $state): string => $state->label())
                    ->color(fn (ProcessingStatus $state): string => $state->color()),

                IconColumn::make('owned')
                    ->label('Owned')
                    ->boolean()
                    ->toggleable(),

                TextColumn::make('user_rating')
                    ->label('Rating')
                    ->formatStateUsing(fn (?int $state): string => $state ? $state.'/10' : '—')
                    ->sortable()
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('processing_status')
                    ->label('Status')
                    ->options(collect(ProcessingStatus::cases())
                        ->mapWithKeys(fn (ProcessingStatus $s) => [$s->value => $s->label()])
                        ->all()),

                SelectFilter::make('genre')
                    ->label('Genre')
                    ->options(fn (): array => static::availableGenres())
                    ->query(fn (Builder $query, array $data): Builder => $query->when(
                        $data['value'] ?? null,
                        fn (Builder $q, string $genre) => $q->whereHas(
                            'tags',
                            fn (Builder $t) => $t->where('type', 'genre')->where('value', $genre),
                        ),
                    )),

                TernaryFilter::make('owned')->label('Owned'),
                TernaryFilter::make('wishlist')->label('Wishlist'),
            ])
            ->recordActions([
                Action::make('convert')
                    // The button carries the state rather than a separate
                    // column: a running encode takes tens of minutes, and a
                    // percentage on the control you pressed is where you look
                    // for it.
                    ->label(fn (MediaItem $record): string => match ($record->transcode_status) {
                        'running' => 'Converting '.$record->transcode_percent.'%',
                        'pending' => 'Queued…',
                        'failed' => 'Convert failed — retry',
                        default => 'Convert for web',
                    })
                    ->icon(fn (MediaItem $record): string => match ($record->transcode_status) {
                        'running', 'pending' => 'heroicon-o-arrow-path',
                        'failed' => 'heroicon-o-exclamation-triangle',
                        default => 'heroicon-o-arrow-path-rounded-square',
                    })
                    ->color(fn (MediaItem $record): string => match ($record->transcode_status) {
                        'running', 'pending' => 'info',
                        'failed' => 'danger',
                        default => 'gray',
                    })
                    // Pressing it mid-encode would queue a second ffmpeg
                    // against the same file.
                    ->disabled(fn (MediaItem $record): bool => in_array(
                        $record->transcode_status,
                        ['pending', 'running'],
                        true,
                    ))
                    ->requiresConfirmation()
                    ->modalDescription('Creates an H.264 MP4 copy that plays in a browser. Your original file is left untouched.')
                    // Shown where conversion would help, and while one is in
                    // flight so its progress stays visible.
                    ->visible(fn (MediaItem $record): bool => app(MediaTranscoder::class)->needsConversion($record)
                        || in_array($record->transcode_status, ['pending', 'running', 'failed'], true))
                    ->action(function (MediaItem $record): void {
                        $record->forceFill([
                            'transcode_status' => 'pending',
                            'transcode_percent' => 0,
                        ])->saveQuietly();

                        TranscodeMediaJob::dispatch($record->id);

                        Notification::make()
                            ->title('Conversion queued')
                            ->body('Encoding runs in the background and can take a while.')
                            ->success()
                            ->send();
                    }),

                Action::make('reenrich')
                    ->label('Re-enrich')
                    ->icon('heroicon-o-arrow-path')
                    ->color('gray')
                    ->requiresConfirmation()
                    ->modalDescription('Re-runs the metadata pipeline. Fields you edited by hand are kept.')
                    ->action(function (MediaItem $record): void {
                        $record->update(['processing_status' => ProcessingStatus::Pending]);
                        EnrichMediaItemJob::dispatch($record->id);

                        Notification::make()->title('Re-enrichment queued')->success()->send();
                    }),
                EditAction::make(),
                // Available for every type: any of them can be re-enriched,
                // and any of them can have a value replaced by a worse one.
                static::historyAction(),
                static::restoreVersionAction(),
                // Per-type actions are appended rather than replacing this
                // list: a table that declared its own recordActions() would
                // silently drop Edit, Convert and Re-enrich.
                ...static::extraRecordActions(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('reenrichBulk')
                        ->label('Re-enrich selected')
                        ->icon('heroicon-o-arrow-path')
                        ->requiresConfirmation()
                        ->action(function (Collection $records): void {
                            $records->each(function (MediaItem $record): void {
                                $record->update(['processing_status' => ProcessingStatus::Pending]);
                                EnrichMediaItemJob::dispatch($record->id);
                            });

                            Notification::make()
                                ->title($records->count().' items queued for re-enrichment')
                                ->success()
                                ->send();
                        })
                        ->deselectRecordsAfterCompletion(),

                    BulkAction::make('setOwned')
                        ->label('Mark as owned')
                        ->icon('heroicon-o-check-circle')
                        ->action(fn (Collection $records) => $records->each->update(['owned' => true]))
                        ->deselectRecordsAfterCompletion(),

                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    /**
     * Imports caption tracks that shipped with a video.
     *
     * Local only — embedded streams and sidecar files. Online search lives in
     * the player, where someone is watching and can judge whether a result
     * actually matches.
     */
    protected static function subtitleAction(): Action
    {
        return Action::make('importSubtitles')
            ->label(fn (MediaItem $record): string => ($count = $record->subtitles()->count()) > 0
                ? $count.' '.str('subtitle')->plural($count)
                : 'Find subtitles')
            ->icon('heroicon-o-chat-bubble-bottom-center-text')
            ->color('gray')
            ->visible(fn (MediaItem $record): bool => $record->hasReadableFile())
            ->action(function (MediaItem $record): void {
                $importer = app(SubtitleImporter::class);

                if (! $importer->isAvailable()) {
                    Notification::make()
                        ->title('ffmpeg is not installed')
                        ->body('Subtitle extraction needs ffmpeg on this server.')
                        ->danger()
                        ->send();

                    return;
                }

                $result = $importer->importAll($record);
                $total = $result['embedded'] + $result['sidecar'];

                Notification::make()
                    ->title($total > 0
                        ? $total.' '.str('track')->plural($total).' imported'
                        : 'No subtitles found in this file')
                    ->body($total > 0
                        ? 'Choose them from the captions menu while watching.'
                        : 'Search online from the player, or drop a .srt next to the video.')
                    ->success()
                    ->send();
            });
    }

    /**
     * Actions specific to one media type.
     *
     * Overridden by a table that needs its own — book OCR, say — without
     * losing the shared ones.
     *
     * @return array<int, mixed>
     */
    protected static function extraRecordActions(): array
    {
        return [];
    }

    /**
     * Whether anything of this type is queued or mid-encode.
     *
     * Gates the table's polling: a table with nothing converting has no reason
     * to re-query every ten seconds.
     */
    protected static function hasActiveTranscode(): bool
    {
        if (! static::showsTranscodeColumn()) {
            return false;
        }

        return MediaItem::unresolved()
            ->where('type', static::mediaType())
            ->whereIn('transcode_status', ['pending', 'running'])
            ->exists();
    }

    /**
     * Whether the conversion column is shown by default.
     *
     * Only video is ever transcoded, so it would be a permanently empty column
     * on the music and book tables. It stays available there via the column
     * toggle rather than being removed outright.
     */
    protected static function showsTranscodeColumn(): bool
    {
        return in_array(
            static::mediaType(),
            [MediaItemType::Movie, MediaItemType::Show],
            true,
        );
    }

    /**
     * Genres present on this resource's type, for the filter dropdown.
     *
     * @return array<string, string>
     */
    protected static function availableGenres(): array
    {
        return MediaItem::unresolved()
            ->where('media_items.type', static::mediaType())
            ->join('media_tags', 'media_tags.media_item_id', '=', 'media_items.id')
            ->where('media_tags.type', 'genre')
            ->distinct()
            ->orderBy('media_tags.value')
            ->pluck('media_tags.value', 'media_tags.value')
            ->all();
    }
}
