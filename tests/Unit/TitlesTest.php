<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace Tests\Unit;

use App\Services\Titles;
use PHPUnit\Framework\TestCase;

/**
 * Trimming that counts characters rather than bytes.
 *
 * `trim($s, " -–—_")` puts the bytes of the two dashes into the mask, and
 * `E2` and `80` begin every character in the General Punctuation block. The
 * result was rows of invalid UTF-8 in the catalogue, found in a log rather
 * than by anyone reading a title.
 */
class TitlesTest extends TestCase
{
    #[\PHPUnit\Framework\Attributes\DataProvider('punctuationLeadingTitles')]
    public function test_a_leading_punctuation_character_survives(string $title): void
    {
        $trimmed = Titles::trim($title);

        $this->assertTrue(mb_check_encoding($trimmed, 'UTF-8'), "{$title} became invalid UTF-8");
        $this->assertSame($title, $trimmed, "{$title} should be untouched");
    }

    public static function punctuationLeadingTitles(): array
    {
        return [
            'right single quote' => ['’Cause I’m a Man'],
            'ellipsis' => ['…baby one more time'],
            'left double quote' => ['“Heroes”'],
            'bullet' => ['•Intro'],
            'en dash inside' => ['Sunday – Monday'],
        ];
    }

    public function test_it_still_trims_what_it_is_meant_to(): void
    {
        $this->assertSame('normal title', Titles::trim('  - normal title -  '));
        $this->assertSame('Song', Titles::trim('_Song_'));
        $this->assertSame('Song', Titles::trim('— Song —'));
        $this->assertSame('Song', Titles::trim('.Song.'));
    }

    public function test_the_dot_is_stripped_and_that_is_a_change(): void
    {
        // Two of the four callers this replaced used " -–—_" with no dot, so a
        // trailing full stop used to survive and now does not. Inert today —
        // LibraryScanner turns dots into spaces before either trim — but the
        // next caller passing a raw title inherits it, so it is pinned here
        // rather than left to be discovered.
        $this->assertSame('Track', Titles::trim('Track.'));
        $this->assertSame('Track.', Titles::trimExact('Track.'));

        // Both still strip everything the old mask did.
        $this->assertSame('Track', Titles::trimExact('_ Track —'));
    }

    public function test_the_old_byte_mask_is_what_this_replaces(): void
    {
        // Kept as the record of the bug: this is what the code did before,
        // and it must not come back.
        $this->assertFalse(
            mb_check_encoding(trim('’Cause I’m a Man', " -–—_"), 'UTF-8'),
            'if this passes, trim() no longer eats multi-byte characters and this class may be unnecessary',
        );
    }

    public function test_an_empty_or_null_value_is_an_empty_string(): void
    {
        $this->assertSame('', Titles::trim(null));
        $this->assertSame('', Titles::trim(''));
        $this->assertSame('', Titles::trim('   ---   '));
    }
}
