<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Filament\Resources\Books\Pages;

use App\Enums\MediaItemType;
use App\Enums\ProcessingStatus;
use App\Filament\Resources\Books\BookResource;
use App\Filament\Resources\Concerns\HasNeedsReviewTab;
use App\Jobs\EnrichMediaItemJob;
use App\Models\MediaItem;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\FileUpload;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

class ListBooks extends ListRecords
{
    use HasNeedsReviewTab;

    protected static string $resource = BookResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('bulkUpload')
                ->label('Upload books')
                ->icon('heroicon-o-arrow-up-tray')
                ->modalSubmitActionLabel('Upload')
                ->modalDescription('Drop in EPUB, PDF, or comic files — each becomes a book and is looked up automatically.')
                ->schema([
                    FileUpload::make('files')
                        ->label('Book files')
                        ->disk(config('filesystems.default'))
                        ->directory(config('library.inbox', 'media/unsorted'))
                        ->multiple()
                        ->acceptedFileTypes([
                            'application/epub+zip',
                            'application/pdf',
                            'application/x-mobipocket-ebook',
                            'application/vnd.amazon.ebook',
                            'application/vnd.comicbook+zip',
                            'application/vnd.comicbook-rar',
                            'application/zip',
                            'application/x-cbr',
                        ])
                        ->maxSize(512000)
                        ->preserveFilenames()
                        ->required(),
                ])
                ->action(function (array $data): void {
                    $paths = $data['files'] ?? [];

                    foreach ($paths as $path) {
                        $item = MediaItem::create([
                            'user_id'           => Auth::id(),
                            'type'              => MediaItemType::Book,
                            // Open Library promotes the real title once the
                            // lookup resolves.
                            'title'             => pathinfo($path, PATHINFO_FILENAME),
                            'file_path'         => $path,
                            'processing_status' => ProcessingStatus::Pending,
                            'owned'             => true,
                        ]);

                        // Sources write into this row rather than creating it.
                        $item->bookMetadata()->create([]);

                        EnrichMediaItemJob::dispatch($item->id);
                    }

                    Notification::make()
                        ->title(count($paths) . ' ' . str('book')->plural(count($paths)) . ' queued')
                        ->body('Details are being looked up in the background.')
                        ->success()
                        ->send();
                }),

            CreateAction::make()->label('Add book'),
        ];
    }

    public function getTabs(): array
    {
        return [
            'all' => Tab::make('All'),

            'owned' => Tab::make('Owned')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('owned', true)),

            'wishlist' => Tab::make('Reading list')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('wishlist', true)),

            'needs_review' => $this->needsReviewTab(),
        ];
    }
}
