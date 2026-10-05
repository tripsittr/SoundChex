<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Filament\Resources\Concerns;

use App\Jobs\DetectDuplicatesJob;
use App\Jobs\EnrichMediaItemJob;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;

/**
 * The maintenance every media type needs, in one place.
 *
 * Enrichment, cover fetching and duplicate detection all existed, but only as
 * console commands and a button or two scattered across unrelated screens —
 * "find duplicates" lived on the Duplicates page and swept the whole library,
 * re-enrichment had no button at all. A library of films could not be re-asked
 * about without a terminal.
 *
 * Grouped into one dropdown rather than five header buttons: they are occasional
 * operations, and a row of five competing with "Add movie" reads as though they
 * are routine.
 *
 * Everything here queues. Each one touches thousands of rows and talks to the
 * network, so none of it can happen inside the request — which is also why the
 * dashboard carries a table of what is waiting.
 *
 * @mixin ListRecords
 */
trait HasLibraryMaintenanceActions
{
    use HasNeedsReviewTab;

    protected function libraryMaintenanceActions(): array
    {
        return [
            ActionGroup::make([
                Action::make('refetchMissingCovers')
                    ->label('Refetch missing covers')
                    ->icon('heroicon-o-photo')
                    ->badge(fn (): ?string => ($n = $this->maintenanceScope(missingCovers: true)->count()) > 0 ? (string) $n : null)
                    ->action(fn () => $this->queueEnrichment($this->maintenanceScope(missingCovers: true), 'covers')),

                Action::make('refetchAllCovers')
                    ->label('Refetch all covers')
                    ->icon('heroicon-o-arrow-path')
                    ->requiresConfirmation()
                    ->modalHeading('Refetch every cover?')
                    ->modalDescription('Re-asks the metadata sources for everything here, including items that already have artwork.')
                    ->action(fn () => $this->queueEnrichment($this->maintenanceScope(), 'covers')),

                Action::make('reEnrichNeedsReview')
                    ->label('Re-enrich items needing review')
                    ->icon('heroicon-o-sparkles')
                    ->badge(fn (): ?string => ($n = static::needsReviewQuery($this->maintenanceScope())->count()) > 0 ? (string) $n : null)
                    ->action(fn () => $this->queueEnrichment(static::needsReviewQuery($this->maintenanceScope()), 'enrichment')),

                Action::make('reEnrichAll')
                    ->label('Re-enrich everything')
                    ->icon('heroicon-o-arrow-path-rounded-square')
                    ->requiresConfirmation()
                    ->modalHeading('Re-enrich the whole list?')
                    ->modalDescription('Every item here is sent back through the metadata sources. This is the slow one.')
                    ->action(fn () => $this->queueEnrichment($this->maintenanceScope(), 'enrichment')),

                Action::make('findDuplicates')
                    ->label('Search for duplicates')
                    ->icon('heroicon-o-document-duplicate')
                    ->requiresConfirmation()
                    ->modalHeading('Search this type for duplicates?')
                    ->modalDescription('Hashes the files to find identical copies. Video is gigabytes each, so this takes a while. Anything found lands in Needs review.')
                    ->action(function (): void {
                        DetectDuplicatesJob::dispatch(static::maintenanceType());

                        Notification::make()
                            ->title('Duplicate search queued')
                            ->body('Anything it finds appears under Needs review.')
                            ->success()
                            ->send();
                    }),
            ])
                ->label('Maintenance')
                ->icon('heroicon-o-wrench-screwdriver')
                ->button()
                ->color('gray'),
        ];
    }

    /**
     * The media type this list page is for, as stored.
     *
     * Taken from the resource's own `mediaType()` — the same declaration that
     * scopes its query — so the duplicate sweep can never disagree with the
     * list it was started from. A resource without one sweeps everything, which
     * is the safe direction: too much work, not the wrong rows.
     */
    protected static function maintenanceType(): ?string
    {
        $resource = static::getResource();

        return method_exists($resource, 'mediaType')
            ? $resource::mediaType()->value
            : null;
    }

    /**
     * The rows any of these act on: this resource's own.
     *
     * Child rows are skipped. An episode is enriched as part of its series, and
     * a series row has no file to read artwork from, so including either spends
     * requests to achieve nothing.
     */
    protected function maintenanceScope(bool $missingCovers = false): Builder
    {
        $query = static::getResource()::getEloquentQuery()->whereNull('parent_id');

        if ($missingCovers) {
            $query->whereNull('cover_image_url');
        }

        return $query;
    }

    /**
     * Queue one enrichment per row.
     *
     * Covers and metadata are the same job because enrichment is what sets
     * both. The label only changes what the notification says, so somebody who
     * pressed "refetch covers" is not told their metadata is being rebuilt.
     */
    protected function queueEnrichment(Builder $query, string $what): void
    {
        $ids = $query->pluck('id');

        if ($ids->isEmpty()) {
            Notification::make()
                ->title('Nothing to do')
                ->body($what === 'covers' ? 'Everything here already has a cover.' : 'Nothing here needs re-enriching.')
                ->info()
                ->send();

            return;
        }

        foreach ($ids as $id) {
            EnrichMediaItemJob::dispatch((int) $id);
        }

        Notification::make()
            ->title($ids->count().' '.str('item')->plural($ids->count()).' queued')
            ->body('Progress is on the dashboard. Anything that cannot be identified lands in Needs review.')
            ->success()
            ->send();
    }
}
