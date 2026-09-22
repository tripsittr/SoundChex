<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace Tests\Unit;

use App\Services\Books\BookTextExtractor;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Cleaning OCR output (S-305): OCR of a decorative page mixes real text with
 * gibberish read off the artwork. The garbled lines are dropped and the real
 * text kept, so a book never shows OCR soup.
 */
class OcrCleaningTest extends TestCase
{
    private function clean(string $text): string
    {
        $method = new ReflectionMethod(BookTextExtractor::class, 'cleanOcr');
        $method->setAccessible(true);

        return $method->invoke(app(BookTextExtractor::class), $text);
    }

    public function test_it_keeps_the_real_title_and_drops_the_artwork_gibberish(): void
    {
        // The Hobbit cover: OCR read "THE HOBBIT / J.R.R. TOLKIEN" correctly but
        // also turned the cover artwork into symbol soup.
        $ocr = "- ~ °\nee ———X—_=_[_.__ 1\nTHE\nHOBBIT\nJ.R.R. TOLKIEN\nXSite iPh NY BRID Bh REPMRM-BSX-BRAN:";

        $this->assertSame("THE\nHOBBIT\nJ.R.R. TOLKIEN", $this->clean($ocr));
    }

    public function test_it_leaves_ordinary_prose_untouched(): void
    {
        $prose = "They did not sing or tell stories that day, even though the weather improved.";

        $this->assertSame($prose, $this->clean($prose));
    }

    public function test_it_keeps_chapter_headings(): void
    {
        $this->assertSame("Chapter III\nA SHORT REST", $this->clean("Chapter III\nA SHORT REST"));
    }

    public function test_it_drops_a_line_that_is_all_symbols(): void
    {
        $this->assertSame('Hello there', $this->clean("Hello there\n=@#—_~^*[]{}"));
    }

    public function test_it_drops_vowelless_and_mixed_case_fragments(): void
    {
        // "BRD BSX" (no vowels) and "XSite iPh" (mid-word capitals) are OCR noise.
        $this->assertSame('', $this->clean('BRD BSX XSite iPh REPMRM'));
    }
}
