<?php

namespace App\Filament\Pages;

use App\Services\LibraryScanner;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
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
     * Anyone who can reach the panel at all. This is the one page an uploader
     * is here for.
     */
    public static function canAccess(): bool
    {
        return Auth::check();
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
                            ->acceptedFileTypes(static::acceptedMimeTypes())
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
     * Only what the scanner can classify.
     *
     * Anything else would sit in the inbox forever: the scanner ignores
     * extensions it does not recognise, so accepting them would be a silent
     * failure rather than an upload.
     *
     * @return array<int, string>
     */
    protected static function acceptedMimeTypes(): array
    {
        // Browsers report inconsistent MIME types for media — an .mkv arrives
        // as video/x-matroska, application/octet-stream, or empty depending on
        // the platform — so extensions are the reliable filter.
        return collect(config('library.type_extensions', []))
            ->flatten()
            ->map(fn (string $extension): string => '.' . $extension)
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
