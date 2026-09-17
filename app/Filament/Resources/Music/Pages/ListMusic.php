<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Filament\Resources\Music\Pages;

use App\Enums\MediaItemType;
use App\Enums\ProcessingStatus;
use App\Filament\Resources\Music\MusicResource;
use App\Jobs\EnrichMediaItemJob;
use App\Models\MediaItem;
use App\Services\LibraryScanner;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\FileUpload;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

class ListMusic extends ListRecords
{
    protected static string $resource = MusicResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('scanFolders')
                ->label('Scan for new files')
                ->icon('heroicon-o-magnifying-glass')
                ->color('gray')
                ->action(function (LibraryScanner $scanner): void {
                    $folders = config('library.watch_folders', []);

                    if ($folders === []) {
                        Notification::make()
                            ->title('No watched folders configured')
                            ->body('Set LIBRARY_WATCH_FOLDERS in .env to enable folder scanning.')
                            ->warning()
                            ->send();

                        return;
                    }

                    $result = $scanner->scan();

                    if ($result['imported'] === 0) {
                        Notification::make()
                            ->title('No new files found')
                            // A file mid-copy is skipped rather than catalogued
                            // half-written, so say so instead of reporting
                            // nothing at all.
                            ->body($result['unsettled'] > 0
                                ? $result['unsettled'] . ' file(s) still being written — try again shortly.'
                                : 'Everything in your watched folders is already catalogued.')
                            ->info()
                            ->send();

                        return;
                    }

                    Notification::make()
                        ->title($result['imported'] . ' new ' . str('file')->plural($result['imported']) . ' found')
                        ->body('Tags are being read in the background.')
                        ->success()
                        ->send();
                }),

            Action::make('bulkUpload')
                ->label('Upload tracks')
                ->icon('heroicon-o-arrow-up-tray')
                ->modalSubmitActionLabel('Upload')
                ->modalDescription('Drop in multiple files — each becomes a track and is tagged automatically.')
                ->schema([
                    FileUpload::make('files')
                        ->label('Audio files')
                        ->disk(config('filesystems.default'))
                        ->directory(config('library.inbox', 'media/unsorted'))
                        ->multiple()
                        ->acceptedFileTypes([
                            'audio/mpeg', 'audio/mp4', 'audio/flac', 'audio/x-flac',
                            'audio/wav', 'audio/x-wav', 'audio/ogg', 'audio/aac',
                            'audio/x-m4a', 'audio/aiff', 'audio/x-aiff',
                        ])
                        ->maxSize(102400)
                        ->preserveFilenames()
                        ->required(),
                ])
                ->action(function (array $data): void {
                    $paths = $data['files'] ?? [];

                    foreach ($paths as $path) {
                        $item = MediaItem::create([
                            'user_id'           => Auth::id(),
                            'type'              => MediaItemType::Music,
                            'title'             => pathinfo($path, PATHINFO_FILENAME),
                            'file_path'         => $path,
                            'processing_status' => ProcessingStatus::Pending,
                            'owned'             => true,
                        ]);

                        // FileTagger writes into this row, so it must exist first.
                        $item->musicMetadata()->create([]);

                        EnrichMediaItemJob::dispatch($item->id);
                    }

                    Notification::make()
                        ->title(count($paths) . ' ' . str('track')->plural(count($paths)) . ' queued')
                        ->body('Tags are being read in the background.')
                        ->success()
                        ->send();
                }),

            CreateAction::make()->label('Add track'),
        ];
    }

    public function getTabs(): array
    {
        return [
            'all' => Tab::make('All'),

            'owned' => Tab::make('Owned')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('owned', true)),

            'wishlist' => Tab::make('Wishlist')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('wishlist', true)),

            'needs_review' => Tab::make('Needs review')
                ->badge(fn (): int => MusicResource::getEloquentQuery()
                    ->whereIn('processing_status', [
                        ProcessingStatus::NeedsReview->value,
                        ProcessingStatus::Failed->value,
                    ])
                    ->count())
                ->badgeColor('warning')
                ->modifyQueryUsing(fn (Builder $query) => $query->whereIn('processing_status', [
                    ProcessingStatus::NeedsReview->value,
                    ProcessingStatus::Failed->value,
                ])),
        ];
    }
}
