<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace Tests\Unit;

use App\Services\Metadata\Sources\Music\FileTagger;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Stripping a playlist-index prefix ("7373. Queer") out of an artist tag,
 * without touching a legitimate number-band ("38 Special") (S-275).
 */
class StripArtistIndexPrefixTest extends TestCase
{
    #[DataProvider('cases')]
    public function test_it_strips_only_a_numeric_dot_index(?string $input, ?string $expected): void
    {
        $this->assertSame($expected, FileTagger::stripIndexPrefix($input));
    }

    public static function cases(): array
    {
        return [
            // The bug: a multi-digit index, a dot, then the real value.
            'four-digit index' => ['7373. Queer', 'Queer'],
            'four-digit with dotted title' => ['7406. Mr. Jones', 'Mr. Jones'],
            'two-digit index' => ['12. Something', 'Something'],
            'long index long value' => ['1041. You\'re in Love with a Psycho', 'You\'re in Love with a Psycho'],

            // Number-bands: a number then a SPACE, never a dot — must survive.
            '38 Special' => ['38 Special', '38 Special'],
            '21 Savage' => ['21 Savage', '21 Savage'],
            '3 Doors Down' => ['3 Doors Down', '3 Doors Down'],
            '60 Ft Dolls' => ['60 Ft Dolls', '60 Ft Dolls'],

            // A single-digit "1." is too weak to be an index we act on.
            'single digit not stripped' => ['1. U2', '1. U2'],

            // Nothing but an index is not a name — leave it for a real source.
            'index only' => ['7373.', '7373.'],

            // Ordinary names and edge inputs.
            'plain name' => ['Garbage', 'Garbage'],
            'name with a dot' => ['Wu-Tang Clan', 'Wu-Tang Clan'],
            'null' => [null, null],
        ];
    }
}
