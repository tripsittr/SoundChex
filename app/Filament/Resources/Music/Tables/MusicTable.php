<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Filament\Resources\Music\Tables;

use App\Enums\ProcessingStatus;
use App\Jobs\EnrichMediaItemJob;
use App\Models\MediaItem;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class MusicTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                ImageColumn::make('cover_image_url')
                    ->label('')
                    ->square()
                    ->size(44)
                    ->defaultImageUrl(fn (): string => 'https://placehold.co/88x88/1f2937/6b7280?text=%E2%99%AB'),

                TextColumn::make('title')
                    ->searchable()
                    ->sortable()
                    ->weight('medium')
                    ->description(fn (MediaItem $record): ?string => $record->musicMetadata?->album),

                TextColumn::make('musicMetadata.artist')
                    ->label('Artist')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('musicMetadata.release_year')
                    ->label('Year')
                    ->sortable()
                    ->toggleable(),

                TextColumn::make('musicMetadata.bpm')
                    ->label('BPM')
                    ->numeric(decimalPlaces: 0)
                    ->sortable()
                    ->toggleable(),

                TextColumn::make('musicMetadata.key')
                    ->label('Key')
                    ->formatStateUsing(fn (?string $state, MediaItem $record): string => trim(
                        (string) $state . ' ' . ($record->musicMetadata?->scale === 'minor' ? 'm' : '')
                    ))
                    ->toggleable(),

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
                    ->formatStateUsing(fn (?int $state): string => $state ? $state . '/10' : '—')
                    ->sortable()
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('processing_status')
                    ->label('Status')
                    ->options(collect(ProcessingStatus::cases())
                        ->mapWithKeys(fn (ProcessingStatus $s) => [$s->value => $s->label()])
                        ->all()),

                SelectFilter::make('key')
                    ->label('Key')
                    ->options(array_combine(
                        ['C', 'C#', 'D', 'D#', 'E', 'F', 'F#', 'G', 'G#', 'A', 'A#', 'B'],
                        ['C', 'C#', 'D', 'D#', 'E', 'F', 'F#', 'G', 'G#', 'A', 'A#', 'B'],
                    ))
                    ->query(fn (Builder $query, array $data): Builder => $query->when(
                        $data['value'] ?? null,
                        fn (Builder $q, string $key) => $q->whereHas(
                            'musicMetadata',
                            fn (Builder $m) => $m->where('key', $key),
                        ),
                    )),

                Filter::make('bpm')
                    ->schema([
                        TextInput::make('bpm_from')->label('BPM from')->numeric()->placeholder('60'),
                        TextInput::make('bpm_to')->label('BPM to')->numeric()->placeholder('180'),
                    ])
                    ->columns(2)
                    ->query(function (Builder $query, array $data): Builder {
                        $from = $data['bpm_from'] ?? null;
                        $to   = $data['bpm_to'] ?? null;

                        if (blank($from) && blank($to)) {
                            return $query;
                        }

                        return $query->whereHas('musicMetadata', function (Builder $m) use ($from, $to) {
                            $m->when(filled($from), fn (Builder $q) => $q->where('bpm', '>=', (float) $from))
                              ->when(filled($to), fn (Builder $q) => $q->where('bpm', '<=', (float) $to));
                        });
                    })
                    ->indicateUsing(function (array $data): array {
                        $from = $data['bpm_from'] ?? null;
                        $to   = $data['bpm_to'] ?? null;

                        if (blank($from) && blank($to)) {
                            return [];
                        }

                        return ['BPM ' . ($from ?: '0') . '–' . ($to ?: '∞')];
                    }),

                SelectFilter::make('genre')
                    ->label('Genre')
                    ->options(fn (): array => MediaItem::query()
                        ->whereHas('tags', fn (Builder $q) => $q->where('type', 'genre'))
                        ->with('tags')
                        ->get()
                        ->flatMap(fn (MediaItem $i) => $i->tags->where('type', 'genre')->pluck('value'))
                        ->unique()
                        ->sort()
                        ->mapWithKeys(fn (string $v) => [$v => $v])
                        ->all())
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
                Action::make('reenrich')
                    ->label('Re-enrich')
                    ->icon('heroicon-o-arrow-path')
                    ->color('gray')
                    ->requiresConfirmation()
                    ->modalDescription('Re-runs the metadata pipeline. Fields you edited by hand are kept.')
                    ->action(function (MediaItem $record): void {
                        $record->update(['processing_status' => ProcessingStatus::Pending]);
                        EnrichMediaItemJob::dispatch($record->id);

                        Notification::make()
                            ->title('Re-enrichment queued')
                            ->success()
                            ->send();
                    }),
                EditAction::make(),
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
                                ->title($records->count() . ' items queued for re-enrichment')
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
            ])
            ->emptyStateHeading('No music yet')
            ->emptyStateDescription('Upload a track — its tags are read automatically.')
            ->emptyStateIcon('heroicon-o-musical-note');
    }
}
