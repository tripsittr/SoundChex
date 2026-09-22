<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace Tests\Unit;

use App\Services\Books\BookTextExtractor;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Unwrapping pdftotext's physical line breaks into flowing paragraphs (S-306),
 * so a reflowable reader wraps clean prose instead of pre-wrapped ragged lines.
 */
class ReflowParagraphsTest extends TestCase
{
    private function reflow(string $text): string
    {
        $method = new ReflectionMethod(BookTextExtractor::class, 'reflowParagraphs');
        $method->setAccessible(true);

        return $method->invoke(app(BookTextExtractor::class), $text);
    }

    public function test_soft_wrapped_lines_join_into_one_paragraph(): void
    {
        // A narrow column pdftotext broke every visual line.
        $wrapped = "positive is a negative, then pursuing the\n"
            ."negative generates the positive. The pain\n"
            ."you pursue in the gym results in better health.";

        $this->assertSame(
            'positive is a negative, then pursuing the negative generates the positive. '
            .'The pain you pursue in the gym results in better health.',
            $this->reflow($wrapped)
        );
    }

    public function test_a_blank_line_is_a_paragraph_break(): void
    {
        $text = "First paragraph wraps\nover two lines.\n\nSecond paragraph\nalso wraps.";

        $this->assertSame(
            "First paragraph wraps over two lines.\n\nSecond paragraph also wraps.",
            $this->reflow($text)
        );
    }

    public function test_a_word_hyphenated_across_a_line_break_is_rejoined(): void
    {
        $this->assertSame('something wicked this way comes.', $this->reflow("some-\nthing wicked this way comes."));
    }

    public function test_it_does_not_glue_words_together(): void
    {
        // Joining lines must add a space, never run words together.
        $this->assertStringContainsString('the negative', $this->reflow("the\nnegative"));
        $this->assertStringNotContainsString('thenegative', $this->reflow("the\nnegative"));
    }
}
