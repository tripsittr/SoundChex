<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace Tests\Feature;

use App\Enums\MediaItemType;
use App\Models\MediaItem;
use App\Models\User;
use App\Services\LibrarySettings;
use App\Services\MediaTranscoder;
use App\Services\OcrService;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * ffmpeg, Tesseract and Poppler are optional. A self-hosted install may have
 * none of them, so every path through these services has to degrade rather
 * than throw — and the OCR language reaches a shell argument, so it is
 * validated rather than trusted.
 *
 * Process is faked throughout: these tests never execute a binary.
 */
class ExternalToolsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
    }

    /* ------------------------------------------------- ocr language ------ */

    /**
     * A malformed value must fall back rather than reach the shell.
     *
     * @return array<string, array{0: string}>
     */
    public static function unsafeLanguages(): array
    {
        return [
            'command chaining' => ['eng; rm -rf /'],
            'substitution' => ['$(whoami)'],
            'backticks' => ['`id`'],
            'pipe' => ['eng | cat /etc/passwd'],
            'path traversal' => ['../../etc/passwd'],
            'flag injection' => ['--tessdata-dir=/tmp'],
            'empty' => [''],
            'wrong shape' => ['english'],
            'trailing plus' => ['eng+'],
            'uppercase' => ['ENG'],
        ];
    }

    #[DataProvider('unsafeLanguages')]
    public function test_an_unsafe_ocr_language_falls_back_to_english(string $language): void
    {
        app(SettingsService::class)->set('ocr_language', $language);

        $this->assertSame('eng', app(LibrarySettings::class)->ocrLanguage());
    }

    public function test_a_valid_ocr_language_is_kept(): void
    {
        // Proves the guard is not simply hardcoding 'eng' and passing every
        // test above for the wrong reason.
        app(SettingsService::class)->set('ocr_language', 'deu');
        $this->assertSame('deu', app(LibrarySettings::class)->ocrLanguage());

        app(SettingsService::class)->set('ocr_language', 'eng+fra');
        $this->assertSame('eng+fra', app(LibrarySettings::class)->ocrLanguage());
    }

    /* ---------------------------------------------------- availability --- */

    public function test_the_transcoder_reports_unavailable_when_ffmpeg_is_missing(): void
    {
        Process::fake(['*' => Process::result(output: '', exitCode: 127)]);

        $this->assertFalse(app(MediaTranscoder::class)->isAvailable());
    }

    public function test_the_transcoder_reports_available_when_ffmpeg_answers(): void
    {
        Process::fake(['*' => Process::result(output: 'ffmpeg version 7.1', exitCode: 0)]);

        $this->assertTrue(app(MediaTranscoder::class)->isAvailable());
    }

    public function test_ocr_needs_both_binaries_not_just_one(): void
    {
        // A machine with Poppler but no Tesseract can render a page and then
        // do nothing with it, which is not "available".
        config()->set('ocr.tesseract_path', '/nonexistent/tesseract');
        config()->set('ocr.pdftoppm_path', '/bin/sh');

        $this->assertFalse(app(OcrService::class)->isAvailable());
    }

    public function test_ocr_reports_unavailable_without_its_binaries(): void
    {
        // Tesseract and Poppler are separate installs; both are required.
        // Absolute paths are checked directly rather than through the shell's
        // lookup, so these resolve to "not executable" without running.
        config()->set('ocr.tesseract_path', '/nonexistent/tesseract');
        config()->set('ocr.pdftoppm_path', '/nonexistent/pdftoppm');

        $this->assertFalse(app(OcrService::class)->isAvailable());
    }

    /* ------------------------------------------------------ conversion --- */

    public function test_conversion_is_not_attempted_for_a_missing_file(): void
    {
        $item = $this->movie('/tmp/definitely-not-here-' . uniqid() . '.mkv');

        $this->assertFalse(app(MediaTranscoder::class)->needsConversion($item));
    }

    public function test_music_and_books_are_never_converted(): void
    {
        // Only video is transcoded for browser playback.
        foreach ([MediaItemType::Music, MediaItemType::Book] as $type) {
            $path = Storage::disk('local')->path('sample-' . $type->value);
            Storage::disk('local')->put('sample-' . $type->value, 'bytes');

            $item = MediaItem::create([
                'user_id' => $this->user->id,
                'type' => $type,
                'title' => 'Sample',
                'file_path' => $path,
                'owned' => true,
            ]);

            $this->assertFalse(app(MediaTranscoder::class)->needsConversion($item->fresh()));
        }
    }

    public function test_a_file_with_an_existing_conversion_is_not_converted_again(): void
    {
        // Re-converting would burn hours of CPU to produce the same file.
        Storage::disk('local')->put('source.mkv', 'bytes');
        Storage::disk('local')->put('converted/out.mp4', 'bytes');

        $item = $this->movie(Storage::disk('local')->path('source.mkv'));
        $item->forceFill(['converted_path' => 'converted/out.mp4'])->save();

        $this->assertFalse(app(MediaTranscoder::class)->needsConversion($item->fresh()));
    }

    public function test_converting_a_missing_file_returns_null_rather_than_throwing(): void
    {
        Process::fake();

        $item = $this->movie('/tmp/definitely-not-here-' . uniqid() . '.mkv');

        $this->assertNull(app(MediaTranscoder::class)->convert($item));
    }

    /* -------------------------------------------------------- helpers --- */

    private function movie(string $path): MediaItem
    {
        return MediaItem::create([
            'user_id' => $this->user->id,
            'type' => MediaItemType::Movie,
            'title' => 'Sample Film',
            'file_path' => $path,
            'owned' => true,
        ])->fresh();
    }
}
