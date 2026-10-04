<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Filament\Resources\Concerns;

use App\Jobs\EnrichMediaItemJob;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;

/**
 * "Refetch covers" for a media list page.
 *
 * Two buttons, because they answer different questions. *Missing* is the one
 * reached for most: some items never got a cover, usually because the source
 * had no key configured at the time, and nothing re-asks on its own. *All*
 * exists for when the covers are present but wrong — a batch matched to the
 * wrong release — and is deliberately the heavier, confirmed one.
 *
 * Both re-run enrichment rather than calling an artwork fetcher directly.
 * Enrichment is what sets `cover_image_url` for every type: TMDB for films and
 * television, the cover-art fetcher for music. A separate artwork-only path
 * would be a second implementation to keep in step, and `RefetchCoversJob`
 * already shows the cost of that — it reads `musicMetadata` and requires
 * `needs_cover_review`, so it cannot serve a film at all.
 *
 * @mixin ListRecords
 */
trait HasCoverRefetchActions
{
    /**
     * @return array<int, Action>
     */
    protected function coverRefetchActions(): array
    {
        return [
            Action::make('refetchMissingCovers')
                ->label('Refetch missing covers')
                ->icon('heroicon-o-photo')
                ->color('gray')
                ->badge(fn (): ?string => ($n = $this->coversMissingCount()) > 0 ? (string) $n : null)
                ->action(fn () => $this->queueCoverRefetch(onlyMissing: true)),

            Action::make('refetchAllCovers')
                ->label('Refetch all covers')
                ->icon('heroicon-o-arrow-path')
                ->color('gray')
                ->requiresConfirmation()
                ->modalHeading('Refetch every cover?')
                ->modalDescription(fn (): string => 'This re-asks the metadata sources for all '
                    .$this->coverScopeQuery(false)->count()
                    .' items here, including those that already have artwork. It runs in the background and can take a while.')
                ->modalSubmitActionLabel('Refetch all')
                ->action(fn () => $this->queueCoverRefetch(onlyMissing: false)),
        ];
    }

    /** How many items on this page have no cover at all. */
    protected function coversMissingCount(): int
    {
        return $this->coverScopeQuery(true)->count();
    }

    /**
     * The rows either button works on: this resource's own, optionally narrowed
     * to those with no cover.
     *
     * A series row is skipped. It has no file to read artwork from and takes
     * its own cover from the series metadata, so re-enriching one does nothing
     * but spend a request.
     */
    protected function coverScopeQuery(bool $onlyMissing): Builder
    {
        $query = static::getResource()::getEloquentQuery()->whereNull('parent_id');

        if ($onlyMissing) {
            $query->whereNull('cover_image_url');
        }

        return $query;
    }

    protected function queueCoverRefetch(bool $onlyMissing): void
    {
        $ids = $this->coverScopeQuery($onlyMissing)->pluck('id');

        if ($ids->isEmpty()) {
            Notification::make()
                ->title($onlyMissing ? 'Every item here already has a cover' : 'Nothing to refetch')
                ->info()
                ->send();

            return;
        }

        foreach ($ids as $id) {
            EnrichMediaItemJob::dispatch((int) $id);
        }

        Notification::make()
            ->title($ids->count().' '.str('item')->plural($ids->count()).' queued')
            ->body('Covers are fetched in the background. The list fills in as they arrive.')
            ->success()
            ->send();
    }
}
