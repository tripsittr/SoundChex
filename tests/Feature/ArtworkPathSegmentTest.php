<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace Tests\Feature;

use App\Services\Metadata\Sources\Music\FileTagger;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Cover art is filed under `artwork/{artist}/{album}/{song}`, and those
 * segments come from tags, which contain whatever the tagger wrote.
 *
 * Windows refuses a directory whose name ends in a dot or a space. "R.E.M." and
 * "Out Of Time (U.S. Version)." both raised UnableToCreateDirectory, so those
 * albums were catalogued and filed while their covers were silently lost — 66
 * failures on one library. `LibraryOrganizer::segment` had always trimmed them;
 * this path had not.
 */
class ArtworkPathSegmentTest extends TestCase
{
    private function segment(?string $value, string $fallback = 'Unknown'): string
    {
        $method = new ReflectionMethod(FileTagger::class, 'pathSegment');

        return $method->invoke(app(FileTagger::class), $value, $fallback);
    }

    /** @return array<string, array{0: string}> */
    public static function namesWindowsRefuses(): array
    {
        return [
            'band with a trailing dot' => ['R.E.M.'],
            'another one' => ['P.H.F.'],
            'album ending in a dot' => ['Out Of Time (U.S. Version).'],
            'trailing space' => ['Spiritualized '],
            'trailing dot and space' => ['Everything Is. '],
        ];
    }

    #[DataProvider('namesWindowsRefuses')]
    public function test_a_segment_never_ends_in_a_dot_or_space(string $name): void
    {
        $segment = $this->segment($name);

        $this->assertDoesNotMatchRegularExpression(
            '/[. ]$/',
            $segment,
            "Windows cannot create a directory named '{$segment}'.",
        );
    }

    public function test_the_meaningful_part_of_the_name_survives(): void
    {
        $this->assertSame('R.E.M', $this->segment('R.E.M.'));
        $this->assertSame('Pavement', $this->segment('Pavement'));
    }

    /**
     * Truncation runs before the trim, so a cut landing on a dot cannot put one
     * back at the end.
     */
    public function test_truncation_cannot_reintroduce_a_trailing_dot(): void
    {
        $name = str_repeat('a', 79).'.tail';

        $this->assertDoesNotMatchRegularExpression('/[. ]$/', $this->segment($name));
    }

    public function test_an_empty_name_falls_back(): void
    {
        $this->assertSame('Unknown Artist', $this->segment('...', 'Unknown Artist'));
        $this->assertSame('Unknown Artist', $this->segment(null, 'Unknown Artist'));
    }
}
