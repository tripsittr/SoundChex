<?php

namespace App\Filament\Resources\Books\Tables;

use App\Enums\MediaItemType;
use App\Filament\Resources\Concerns\BuildsMediaTable;
use App\Jobs\ExtractBookAssetsJob;
use App\Jobs\OcrBookJob;
use App\Models\MediaItem;
use App\Services\Books\PdfAssetExtractor;
use App\Services\OcrService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class BooksTable
{
    use BuildsMediaTable;

    /** Required by BuildsMediaTable for the genre filter. */
    public static function mediaType(): MediaItemType
    {
        return MediaItemType::Book;
    }

    public static function configure(Table $table): Table
    {
        return static::baseTable($table, [
            TextColumn::make('bookMetadata.publish_year')
                ->label('Year')
                ->sortable()
                ->toggleable(),

            TextColumn::make('bookMetadata.pages')
                ->label('Pages')
                ->alignEnd()
                ->placeholder('—')
                ->sortable()
                ->toggleable(),

            TextColumn::make('bookMetadata.series_name')
                ->label('Series')
                ->formatStateUsing(function (?string $state, $record): string {
                    if (blank($state)) {
                        return '—';
                    }

                    $position = $record->bookMetadata?->series_position;

                    return $position ? "{$state} #{$position}" : $state;
                })
                ->toggleable(isToggledHiddenByDefault: true),

            TextColumn::make('bookMetadata.isbn_13')
                ->label('ISBN')
                ->copyable()
                ->placeholder('—')
                ->toggleable(isToggledHiddenByDefault: true),

            // Only meaningful for PDFs, and only once a pass has run — an
            // EPUB is text by definition.
            TextColumn::make('ocr_status')
                ->label('Text recognition')
                ->badge()
                ->state(fn (MediaItem $record): string => match ($record->ocr_status) {
                    'running' => 'Recognising ' . $record->ocr_percent . '%',
                    'pending' => 'Queued',
                    'failed' => 'Failed',
                    'complete' => $record->scanned_page_count > 0
                        ? $record->scanned_page_count . ' pages recognised'
                        : 'No scans found',
                    default => '—',
                })
                ->color(fn (MediaItem $record): string => match ($record->ocr_status) {
                    'running', 'pending' => 'warning',
                    'failed' => 'danger',
                    'complete' => 'success',
                    default => 'gray',
                })
                ->toggleable(),
        ], placeholder: '📖')
            ->emptyStateHeading('No books yet')
            ->emptyStateDescription('Scan an ISBN or type a title — Open Library does the rest.')
            ->emptyStateIcon('heroicon-o-book-open');
    }

    /**
     * @return array<int, Action>
     */
    protected static function extraRecordActions(): array
    {
        return [static::ocrAction(), static::extractAssetsAction()];
    }

    /**
     * Recovers the illustrations, outline and searchable text inside a PDF.
     *
     * All of it is already in the file — this is what makes it reachable.
     */
    private static function extractAssetsAction(): Action
    {
        return Action::make('extractAssets')
            ->label(fn (MediaItem $record): string => ($count = $record->bookAssets()->count()) > 0
                ? $count . ' ' . str('illustration')->plural($count)
                : 'Extract contents')
            ->icon('heroicon-o-photo')
            ->color('gray')
            ->visible(fn (MediaItem $record): bool => str_ends_with(
                strtolower((string) $record->file_path),
                '.pdf',
            ))
            ->action(function (MediaItem $record): void {
                if (! app(PdfAssetExtractor::class)->isAvailable()) {
                    Notification::make()
                        ->title('Poppler is not installed')
                        ->body('Install it with: brew install poppler')
                        ->danger()
                        ->send();

                    return;
                }

                ExtractBookAssetsJob::dispatch($record->id);

                Notification::make()
                    ->title('Reading the file')
                    ->body('Illustrations, chapters and page text are being extracted in the background.')
                    ->success()
                    ->send();
            });
    }

    /**
     * Recognises every scanned page in a book.
     *
     * Offered only for PDFs: an EPUB is already text, and a comic archive has
     * no page text to recover.
     */
    private static function ocrAction(): Action
    {
        return Action::make('ocr')
            ->label(fn (MediaItem $record): string => match ($record->ocr_status) {
                'running' => 'Recognising ' . $record->ocr_percent . '%',
                'pending' => 'Queued…',
                'complete' => 'Re-run text recognition',
                default => 'Recognise scanned text',
            })
            ->icon('heroicon-o-document-magnifying-glass')
            ->color('gray')
            ->visible(fn (MediaItem $record): bool => str_ends_with(
                strtolower((string) $record->file_path),
                '.pdf',
            ))
            ->disabled(fn (MediaItem $record): bool => in_array(
                $record->ocr_status,
                ['pending', 'running'],
                true,
            ))
            ->requiresConfirmation()
            ->modalHeading('Recognise scanned text?')
            ->modalDescription('Pages that already have text are skipped. A long scanned book can take several minutes, and runs in the background.')
            ->action(function (MediaItem $record): void {
                if (! app(OcrService::class)->isAvailable()) {
                    Notification::make()
                        ->title('Text recognition is not installed')
                        ->body('Install Tesseract and Poppler on this server: brew install tesseract poppler')
                        ->danger()
                        ->send();

                    return;
                }

                $record->forceFill([
                    'ocr_status' => 'pending',
                    'ocr_percent' => 0,
                ])->saveQuietly();

                OcrBookJob::dispatch($record->id, force: $record->ocr_status === 'complete');

                Notification::make()
                    ->title('Recognising text')
                    ->body('Progress appears in this row. Scanned pages become selectable as they finish.')
                    ->success()
                    ->send();
            });
    }
}
