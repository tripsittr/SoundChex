<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Filament\Pages;

use App\Filament\Concerns\RestrictsToAdmins;
use App\Services\LibraryScanner;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/**
 * Adds files to the library.
 *
 * Deliberately the only page an uploader can reach. Everything else in the
 * panel carries RestrictsToAdmins, so adding a song does not also grant user
 * management, the metadata API keys, or any delete action.
 *
 * Files land in the inbox and are catalogued, enriched and filed by the same
 * pipeline that handles a file dropped there by hand. One path, not two — an
 * upload that bypassed the scanner would skip duplicate detection and land
 * somewhere the organizer never looks.
 */
class BulkUpload extends Page
{
    use RestrictsToAdmins;

    protected string $view = 'filament.pages.bulk-upload';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowUpTray;

    protected static string|UnitEnum|null $navigationGroup = 'Media Library';

    protected static ?string $navigationLabel = 'Upload';

    protected static ?string $title = 'Add to the library';

    protected static ?int $navigationSort = -10;

    /**
     * @var array<string, mixed>
     */
    public ?array $data = [];

    /**
     * Initialises the form state.
     *
     * Without this the page renders but nothing can be uploaded: `$data` is an
     * empty array, so `data.files` does not exist, and Livewire refuses to
     * bind the file input to a property it cannot find —
     *
     *   Livewire property ['data.files'] cannot be found on component
     *
     * The uploader then sits there accepting a file and doing nothing with it,
     * with no server-side error to explain why.
     */
    public function mount(): void
    {
        $this->form->fill(['files' => []]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Section::make('Files')
                    ->description('Music, films, TV episodes and books. They are identified and filed automatically once uploaded — nothing needs naming or sorting by hand.')
                    ->schema([
                        FileUpload::make('files')
                            ->label('')
                            ->multiple()
                            ->disk('local')
                            // The inbox on the private disk. The scanner
                            // sweeps this and the organizer moves files out of
                            // it once enrichment resolves real metadata.
                            ->directory(trim((string) config('library.inbox', 'media/unsorted'), '/'))
                            // Keeps the original name: the scanner reads the
                            // series, season and episode out of it, and a
                            // hashed name would lose all of that.
                            ->preserveFilenames()
                            // Two separate jobs, and conflating them broke
                            // every upload.
                            //
                            // acceptedFileTypes() drives the browser's file
                            // picker AND a `mimetypes:` validation rule, which
                            // rejects anything that is not a real MIME type —
                            // so passing ".mp3" failed every file before it
                            // reached the disk.
                            //
                            // Three layers, each needing a different list, and
                            // getting this wrong broke uploads twice.
                            //
                            // 1. FilePond checks the browser-reported MIME
                            //    type client-side and refuses anything not in
                            //    acceptedFileTypes() with "File of invalid
                            //    type" — before a single byte is sent. It
                            //    needs real MIME types; dotted extensions are
                            //    rejected outright.
                            //
                            // 2. `mimetypes:` validation would then compare
                            //    the same unreliable value server-side.
                            //
                            // 3. `extensions:` checks the filename, which is
                            //    the only dependable signal: a real .mkv is
                            //    routinely detected as application/octet-
                            //    stream, so anything MIME-based rejects it.
                            //
                            // So: MIME types (plus octet-stream) for the
                            // picker, and the filename for the rule that
                            // actually decides.
                            ->acceptedFileTypes(static::acceptedFileTypes())
                            ->rule('extensions:' . implode(',', static::acceptedBareExtensions()))
                            ->maxSize(static::maxKilobytes())
                            ->uploadingMessage('Uploading — large files take a while on a home connection.')
                            ->helperText(static::acceptedSummary()),
                    ]),
            ]);
    }

    public function save(LibraryScanner $scanner): void
    {
        $files = $this->form->getState()['files'] ?? [];

        if ($files === []) {
            Notification::make()
                ->title('Nothing to upload')
                ->warning()
                ->send();

            return;
        }

        // Scanned immediately rather than waiting for the schedule: someone
        // who just uploaded a file expects to see it, not to wonder whether it
        // worked for the next five minutes.
        $result = $scanner->scan();

        $this->form->fill(['files' => []]);

        Notification::make()
            ->title(count($files) . ' ' . str('file')->plural(count($files)) . ' uploaded')
            ->body($result['imported'] > 0
                ? $result['imported'] . ' catalogued and queued for identification.'
                : 'Already in the library, or still being written — the next scan will pick anything up.')
            ->success()
            ->send();
    }

    protected function getFormActions(): array
    {
        return [
            Action::make('save')
                ->label('Add to library')
                ->submit('save'),
        ];
    }

    /**
     * MIME types for FilePond's client-side check.
     *
     * Derived from the extension list so the two cannot drift, with
     * application/octet-stream included because that is what browsers report
     * for .mkv, .flac and friends on several platforms. Without it FilePond
     * refuses those files before they are ever sent, and the server-side rule
     * that would have accepted them never runs.
     *
     * @return array<int, string>
     */
    protected static function acceptedFileTypes(): array
    {
        $types = collect(static::acceptedBareExtensions())
            ->map(fn (string $extension): ?string => match ($extension) {
                'mp3' => 'audio/mpeg',
                'flac' => 'audio/flac',
                'm4a', 'aac', 'alac' => 'audio/mp4',
                'wav' => 'audio/wav',
                'aiff', 'aif' => 'audio/aiff',
                'ogg', 'oga' => 'audio/ogg',
                'opus' => 'audio/opus',
                'wma' => 'audio/x-ms-wma',
                'mp4', 'm4v' => 'video/mp4',
                'mkv' => 'video/x-matroska',
                'avi' => 'video/x-msvideo',
                'mov' => 'video/quicktime',
                'webm' => 'video/webm',
                'wmv' => 'video/x-ms-wmv',
                'epub' => 'application/epub+zip',
                'pdf' => 'application/pdf',
                'mobi', 'azw3' => 'application/x-mobipocket-ebook',
                'cbz' => 'application/vnd.comicbook+zip',
                'cbr' => 'application/vnd.comicbook-rar',
                default => null,
            })
            ->filter()
            ->unique()
            ->values()
            ->all();

        // The catch-all, and the dotted extensions, so a file whose type the
        // browser cannot name still reaches the server-side check.
        return array_merge($types, ['application/octet-stream'], static::acceptedExtensions());
    }

    /**
     * Extensions with a leading dot, for the browser's file picker.
     *
     * Only what the scanner can classify: anything else would sit in the inbox
     * forever, because the scanner ignores extensions it does not recognise —
     * a silent failure rather than an upload.
     *
     * The `accept` attribute takes either MIME types or dotted extensions, and
     * extensions are the reliable choice for media: an .mkv is reported as
     * video/x-matroska, application/octet-stream, or nothing at all depending
     * on the platform.
     *
     * @return array<int, string>
     */
    protected static function acceptedExtensions(): array
    {
        return collect(static::acceptedBareExtensions())
            ->map(fn (string $extension): string => '.' . $extension)
            ->all();
    }

    /**
     * The same list without dots, for Laravel's `extensions:` rule.
     *
     * `extensions:` checks the filename. Both MIME-based rules are wrong here:
     * `mimetypes:` compares the real type, and `mimes:` maps the detected type
     * back to an extension — a real .mkv is detected as octet-stream and fails
     * both, which is exactly why extensions are the filter.
     *
     * @return array<int, string>
     */
    protected static function acceptedBareExtensions(): array
    {
        return collect(config('library.type_extensions', []))
            ->flatten()
            ->map(fn (string $extension): string => ltrim($extension, '.'))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Filament's limit in kilobytes, taken from PHP's own so the two cannot
     * disagree. A form that accepts more than the server does fails after the
     * upload rather than before it.
     */
    protected static function maxKilobytes(): int
    {
        $limit = static::bytesFromIni(ini_get('upload_max_filesize'));
        $post = static::bytesFromIni(ini_get('post_max_size'));

        return (int) (min($limit ?: PHP_INT_MAX, $post ?: PHP_INT_MAX) / 1024);
    }

    private static function bytesFromIni(string|false $value): int
    {
        if ($value === false || $value === '') {
            return 0;
        }

        $unit = strtolower(substr($value, -1));
        $number = (int) $value;

        return match ($unit) {
            'g' => $number * 1024 ** 3,
            'm' => $number * 1024 ** 2,
            'k' => $number * 1024,
            default => $number,
        };
    }

    protected static function acceptedSummary(): string
    {
        $limit = round(static::maxKilobytes() / 1024 / 1024, 1);

        return 'Accepted: '
            . collect(config('library.type_extensions', []))
                ->map(fn (array $extensions): string => strtoupper(implode(', ', array_slice($extensions, 0, 4))))
                ->implode(' · ')
            . '. Up to ' . $limit . ' GB per file.';
    }
}
