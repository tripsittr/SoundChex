<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace Tests\Feature;

use App\Enums\MediaItemType;
use App\Services\LibraryScanner;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Filenames the scanner refused, and said nothing about.
 *
 * Five music files sat in the inbox for four months. The scan found them,
 * decided it could not read a title out of the name, and skipped each with a
 * bare `continue` — not counted, not logged. `library:scan` printed nothing and
 * exited 0, which is indistinguishable from an inbox with nothing new in it.
 *
 * Two separate bugs got them there, and the fixtures below are the real names.
 */
class ScannerTitleCleaningTest extends TestCase
{
    private function clean(string $filename, MediaItemType $type = MediaItemType::Music): ?string
    {
        $method = new ReflectionMethod(LibraryScanner::class, 'cleanTitle');
        $method->setAccessible(true);

        return $method->invoke(app(LibraryScanner::class), $filename, $type);
    }

    /* ------------------------------------------- the "by Author" strip --- */

    /**
     * A track whose title begins with "By" survives.
     *
     * The author strip exists for books — "Dune by Frank Herbert" — and ran on
     * every type. On "01 - By My Side" it matched " By My Side", stripped it,
     * and left "01 -", which then read as a filename with no title in it.
     */
    public function test_a_title_beginning_with_by_is_kept(): void
    {
        $this->assertSame('By My Side', $this->clean('01 - By My Side'));
        $this->assertSame('By the Time I Get to Phoenix', $this->clean('01 - By the Time I Get to Phoenix'));
        $this->assertSame('By the Time I Get to Phoenix', $this->clean('04 - By the Time I Get to Phoenix'));
    }

    /** And one that merely contains "by" mid-title. */
    public function test_by_in_the_middle_of_a_music_title_is_kept(): void
    {
        $this->assertSame('Stand by Me', $this->clean('07 - Stand by Me'));
        $this->assertSame('Knocked Down by a Feeling', $this->clean('Knocked Down by a Feeling'));
    }

    /**
     * Books still drop the author, which is what the strip is for.
     */
    public function test_a_book_still_loses_its_author(): void
    {
        $this->assertSame('Dune', $this->clean('Dune by Frank Herbert', MediaItemType::Book));
    }

    /**
     * A book whose title starts with "By" keeps it.
     *
     * The pattern now requires something before the "by", so the clause has to
     * be an author clause rather than the whole title.
     */
    public function test_a_book_titled_by_something_is_not_emptied(): void
    {
        $this->assertSame('By Grand Central Station', $this->clean('By Grand Central Station', MediaItemType::Book));
    }

    /* ----------------------------------------- characters, not bytes --- */

    /**
     * A short title made of multibyte characters is not a generated hash.
     *
     * "D-I-V-O-R-C-E" here uses U+2010 hyphens, three bytes each: thirteen
     * characters measured twenty-five by `strlen`, past the 24-byte limit meant
     * to catch Livewire temp-upload names. Any title with accents or
     * typographic punctuation was liable to the same thing.
     */
    public function test_a_multibyte_title_is_not_mistaken_for_a_hash(): void
    {
        $divorce = "D\u{2010}I\u{2010}V\u{2010}O\u{2010}R\u{2010}C\u{2010}E";

        $this->assertSame(25, strlen($divorce), 'The fixture must be over the byte limit for this to test anything.');
        $this->assertLessThan(24, mb_strlen($divorce), 'And under it in characters.');

        $this->assertSame($divorce, $this->clean('02 - '.$divorce));
    }

    public function test_an_accented_single_word_title_survives(): void
    {
        // 21 characters, 27 bytes.
        $title = 'Prélùdeàçãoèíüñòøåæœ¿';

        $this->assertGreaterThan(24, strlen($title));
        $this->assertLessThan(24, mb_strlen($title));

        $this->assertSame($title, $this->clean($title));
    }

    /**
     * And a real generated name is still rejected.
     *
     * This is the case the guard exists for; widening it to characters must not
     * let temp uploads through.
     */
    public function test_a_generated_upload_name_is_still_rejected(): void
    {
        $this->assertNull($this->clean('phpA1b2C3d4E5f6G7h8I9j0K1l2M3n4O5p6'));
    }

    /* ------------------------------------------------ still rejected --- */

    /** A name with nothing in it but a number is still not a title. */
    public function test_a_bare_track_number_is_still_rejected(): void
    {
        $this->assertNull($this->clean('01 - '));
        $this->assertNull($this->clean('  '));
    }
}
