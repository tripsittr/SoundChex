<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace Tests\Feature;

use App\Filament\Pages\BulkUpload;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Upload validation.
 *
 * Every upload failed once, because the accepted-types list was fed to
 * Laravel's `mimetypes:` rule, which compares against real MIME types and
 * rejects ".mp3" outright.
 *
 * The list is extensions on purpose, and the rule has to be `extensions:`,
 * which checks the filename. `mimes:` is not a substitute: it maps the
 * *detected* type back to an extension, so a real .mkv — routinely detected as
 * application/octet-stream — fails it. Caught here by a test that expected it
 * to pass.
 *
 * These assert the rule accepts real media and still refuses what the scanner
 * could never classify.
 */
class BulkUploadTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, array{0: string, 1: string}> */
    public static function acceptedFiles(): array
    {
        return [
            'mp3' => ['song.mp3', 'audio/mpeg'],
            'flac' => ['song.flac', 'audio/flac'],
            // Reported as octet-stream by some platforms, which is exactly why
            // validation cannot depend on the browser's MIME type.
            'mkv reported as octet-stream' => ['film.mkv', 'application/octet-stream'],
            'mp4' => ['film.mp4', 'video/mp4'],
            'epub' => ['book.epub', 'application/epub+zip'],
            'pdf' => ['book.pdf', 'application/pdf'],
        ];
    }

    #[DataProvider('acceptedFiles')]
    public function test_it_accepts_media_the_scanner_can_classify(string $name, string $mime): void
    {
        $this->assertTrue(
            $this->passes(UploadedFile::fake()->create($name, 64, $mime)),
            $name.' should be accepted',
        );
    }

    /** @return array<string, array{0: string}> */
    public static function rejectedFiles(): array
    {
        return [
            'executable' => ['payload.exe'],
            'script' => ['script.sh'],
            'archive' => ['bundle.zip'],
            'no extension' => ['README'],
        ];
    }

    #[DataProvider('rejectedFiles')]
    public function test_it_refuses_what_the_scanner_would_ignore(string $name): void
    {
        // Accepting these would be a silent failure rather than an upload:
        // the scanner skips extensions it does not recognise, so the file
        // would sit in the inbox forever.
        $this->assertFalse(
            $this->passes(UploadedFile::fake()->create($name, 64, 'application/octet-stream')),
            $name.' should be refused',
        );
    }

    /** @return array<string, array{0: string}> */
    public static function browserReportedTypes(): array
    {
        return [
            // The one that broke it. The browser does not sniff the file; on
            // Windows it reads the registry, and the registry calls .avi
            // `video/avi`. The picker listed only the IANA name, so every .avi
            // was refused before a byte was sent — with "Expects audio/mpeg,
            // audio/flac, …" and no hint that the name was the problem.
            'avi, as Windows reports it' => ['video/avi'],
            'avi, as IANA names it' => ['video/x-msvideo'],
            // .mpg had no entry of any kind.
            'mpg' => ['video/mpeg'],
            'mp4' => ['video/mp4'],
            'mkv' => ['video/x-matroska'],
            'm4a' => ['audio/mp4'],
            'mp3' => ['audio/mpeg'],
            'a type the browser cannot name' => ['application/octet-stream'],
            'epub' => ['application/epub+zip'],
        ];
    }

    /**
     * The picker must accept whatever name the browser puts on a file, because
     * that name varies by machine. The filename is what actually decides, via
     * the `extensions:` rule above.
     */
    #[DataProvider('browserReportedTypes')]
    public function test_the_picker_accepts_the_type_the_browser_reports(string $mime): void
    {
        $accepted = $this->staticValue('acceptedFileTypes');

        // How both FilePond and Laravel's `mimetypes` rule match: an exact
        // entry, or a `type/*` wildcard.
        $matches = in_array($mime, $accepted, true)
            || in_array(explode('/', $mime)[0].'/*', $accepted, true);

        $this->assertTrue($matches, "The picker refuses {$mime} before the file is sent.");
    }

    public function test_the_picker_list_and_the_validation_list_agree(): void
    {
        // They are derived from one config key, and drifting apart would mean
        // a file the picker offers is then rejected on upload.
        $picker = $this->staticValue('acceptedExtensions');
        $rule = $this->staticValue('acceptedBareExtensions');

        $this->assertSame(
            $rule,
            array_map(fn (string $extension): string => ltrim($extension, '.'), $picker),
        );
    }

    public function test_the_size_limit_does_not_exceed_what_php_accepts(): void
    {
        // A form that accepts more than the server does fails after the whole
        // file has been transferred, which on a home connection is minutes.
        $max = $this->staticValue('maxKilobytes');

        $this->assertGreaterThan(0, $max);
        $this->assertLessThanOrEqual(
            (int) (self::iniBytes(ini_get('upload_max_filesize')) / 1024),
            $max,
        );
    }

    private function passes(UploadedFile $file): bool
    {
        return Validator::make(
            ['file' => $file],
            ['file' => 'extensions:'.implode(',', $this->staticValue('acceptedBareExtensions'))],
        )->passes();
    }

    /**
     * Reads a protected static helper off the page.
     *
     * Named to avoid TestCase::call(), which is the HTTP request helper — a
     * private override of it is a fatal error rather than a shadow.
     *
     * @return mixed
     */
    private function staticValue(string $method)
    {
        $reflection = new \ReflectionMethod(BulkUpload::class, $method);
        $reflection->setAccessible(true);

        return $reflection->invoke(null);
    }

    private static function iniBytes(string|false $value): int
    {
        if ($value === false || $value === '') {
            return PHP_INT_MAX;
        }

        $unit = strtolower(substr($value, -1));
        $number = (int) $value;

        return match ($unit) {
            'g' => $number * 1073741824,
            'm' => $number * 1048576,
            'k' => $number * 1024,
            default => $number,
        };
    }
}
