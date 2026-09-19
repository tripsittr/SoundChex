<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Filament\Pages;

use App\Filament\Concerns\RestrictsToAdmins;
use App\Jobs\DetectDuplicatesJob;
use App\Services\LibrarySettings;
use App\Services\OcrService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/**
 * Scanning, organizing, and duplicate-handling controls.
 *
 * Values persist to the settings table via LibrarySettings, so they survive a
 * config or cache clear — unlike settings held only in the cache.
 */
class LibrarySettingsPage extends Page
{
    use RestrictsToAdmins;

    protected string $view = 'filament.pages.library-settings';

    protected static ?string $slug = 'library-settings';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedFolderOpen;

    protected static string|UnitEnum|null $navigationGroup = 'Settings';

    protected static ?string $navigationLabel = 'Library';

    protected static ?string $title = 'Library Settings';

    protected static ?int $navigationSort = 2;

    /**
     * @var array<string, mixed>
     */
    public ?array $data = [];

    public function mount(): void
    {
        $this->form->fill(app(LibrarySettings::class)->all());
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Section::make('Duplicate detection')
                    ->description('Identical files are compared byte-for-byte. Music can also be matched as the same recording across different files — a different bitrate, format, or re-rip.')
                    ->schema([
                        Toggle::make('library_detect_duplicates')
                            ->label('Detect duplicates')
                            ->helperText('Hash each file as it is catalogued and flag identical copies.')
                            ->live(),

                        Toggle::make('library_detect_content_duplicates')
                            ->label('Also match the same recording in a different file (music)')
                            ->helperText('Uses ISRC, MusicBrainz, or an audio fingerprint, and as a fallback the tags (artist, title, album) with a near-equal length. These are only ever flagged for review — never deleted automatically, since the files genuinely differ and only you should choose which to keep.')
                            ->live()
                            ->visible(fn (Get $get): bool => (bool) $get('library_detect_duplicates')),

                        TextInput::make('library_duplicate_duration_tolerance')
                            ->label('Length tolerance for tag matches')
                            ->numeric()
                            ->suffix('sec')
                            ->minValue(0)
                            ->helperText('How far two tracks’ lengths may differ and still count as the same recording when matched by tags. A couple of seconds absorbs encoder differences.')
                            ->visible(fn (Get $get): bool => (bool) $get('library_detect_duplicates')
                                && (bool) $get('library_detect_content_duplicates')),

                        Select::make('library_duplicate_action')
                            ->label('When a duplicate is found')
                            ->options([
                                'review' => 'Flag it and wait for me to confirm',
                                'auto' => 'Delete the redundant copy automatically',
                                'report' => 'Only record it — never delete anything',
                            ])
                            ->helperText('Deleting automatically is unattended: files are removed without anyone seeing them first. Only the redundant copy is touched, and only when the bytes still match.')
                            ->native(false)
                            ->visible(fn (Get $get): bool => (bool) $get('library_detect_duplicates')),

                        TextInput::make('library_hash_max_megabytes')
                            ->label('Skip files larger than')
                            ->numeric()
                            ->suffix('MB')
                            ->minValue(0)
                            ->helperText('Hashing a very large video costs real time. Files above this size are never flagged as duplicates. 0 removes the limit.')
                            ->visible(fn (Get $get): bool => (bool) $get('library_detect_duplicates')),
                    ]),

                Section::make('Scanning')
                    ->description('How new files are discovered.')
                    ->schema([
                        Toggle::make('library_scan_storage')
                            ->label('Sweep the whole storage folder')
                            ->helperText('Picks up uploads and files dropped in by hand, wherever they landed. The organized library is always skipped.'),

                        TextInput::make('library_scan_interval')
                            ->label('Scan every')
                            ->numeric()
                            ->suffix('minutes')
                            ->minValue(1)
                            ->required()
                            ->helperText('How often the scheduled scan looks for new files.'),

                        TextInput::make('library_settle_seconds')
                            ->label('Wait before reading a new file')
                            ->numeric()
                            ->suffix('seconds')
                            ->minValue(0)
                            ->required()
                            ->helperText('A file still being copied would be catalogued half-written. Raise this if you copy large files over a slow network.'),
                    ]),

                Section::make('Text recognition (OCR)')
                    ->description(static::ocrDescription())
                    ->schema([
                        Toggle::make('ocr_on_demand')
                            ->label('Recognise scanned pages as they are opened')
                            ->helperText('A scanned page has no text to select or highlight. Turning this on recognises one in the background the moment a reader reaches it.')
                            ->disabled(fn (): bool => ! app(OcrService::class)->isAvailable()),

                        Select::make('ocr_language')
                            ->label('Language')
                            ->options(static::languageOptions())
                            ->helperText('Recognition is far more accurate when the language matches. Install more with: brew install tesseract-lang')
                            ->native(false)
                            ->disabled(fn (): bool => ! app(OcrService::class)->isAvailable()),
                    ]),

                Section::make('Organizing')
                    ->description('Filing catalogued media into the sorted library tree.')
                    ->schema([
                        Toggle::make('library_auto_organize')
                            ->label('File automatically after enrichment')
                            ->helperText('Move files into Artist/Album and Title (Year) folders once their metadata is resolved. Turn this off to sort manually.'),
                    ]),
            ]);
    }

    /**
     * Says plainly whether OCR can run, since the controls are useless
     * without the binaries and the reason isn't otherwise discoverable.
     */
    protected static function ocrDescription(): string
    {
        return app(OcrService::class)->isAvailable()
            ? 'Scanned pages are images, so they cannot be selected, searched or highlighted until the text is recognised. This runs entirely on this machine — nothing is uploaded.'
            : 'Not available: Tesseract and Poppler are not installed on this server. Install them with "brew install tesseract poppler" (macOS) or "apt install tesseract-ocr poppler-utils" (Debian), then reload this page.';
    }

    /**
     * @return array<string, string>
     */
    protected static function languageOptions(): array
    {
        $names = [
            'eng' => 'English',
            'fra' => 'French',
            'deu' => 'German',
            'spa' => 'Spanish',
            'ita' => 'Italian',
            'por' => 'Portuguese',
            'nld' => 'Dutch',
            'rus' => 'Russian',
            'jpn' => 'Japanese',
            'chi_sim' => 'Chinese (Simplified)',
            'kor' => 'Korean',
        ];

        $installed = app(OcrService::class)->availableLanguages();

        if ($installed === []) {
            return ['eng' => 'English'];
        }

        // Only what's actually installed — offering a language whose data is
        // missing would fail at recognition time with nothing to explain it.
        return collect($installed)
            ->mapWithKeys(fn (string $code): array => [$code => $names[$code] ?? $code])
            ->all();
    }

    public function save(): void
    {
        $state = $this->form->getState();

        app(LibrarySettings::class)->save($state);

        Notification::make()
            ->title('Library settings saved')
            ->success()
            ->send();
    }

    protected function getFormActions(): array
    {
        return [
            Action::make('save')
                ->label('Save changes')
                ->submit('save'),
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('detectDuplicates')
                ->label('Scan for duplicates now')
                ->icon('heroicon-o-document-duplicate')
                ->color('gray')
                ->action(function (): void {
                    DetectDuplicatesJob::dispatch();

                    Notification::make()
                        ->title('Scanning for duplicates')
                        ->body('Results appear under Media Library → Duplicates.')
                        ->success()
                        ->send();
                }),
        ];
    }
}
