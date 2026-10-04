<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace Tests\Feature;

use App\Services\Metadata\Sources\Movie\Tmdb;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use Tests\TestCase;

/**
 * A release's edition wording is not part of the film's name.
 *
 * "The Goonies (1985) 30Th Anniversary Edition.mkv" is catalogued as "The
 * Goonies 30Th Anniversary Edition 1985". The scanner strips resolution, codec
 * and release group but not this, so TMDB searched a title no film has and
 * answered no_match for a film it plainly knows.
 */
class TmdbEditionWordingTest extends TestCase
{
    private function clean(string $title): string
    {
        $method = new ReflectionMethod(Tmdb::class, 'withoutEditionWording');

        return $method->invoke(app(Tmdb::class), $title);
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function releases(): array
    {
        return [
            'the one that failed' => ['The Goonies 30Th Anniversary Edition', 'The Goonies'],
            'anniversary alone' => ['Alien 40th Anniversary', 'Alien'],
            'special edition' => ['Blade Runner Special Edition', 'Blade Runner'],
            "director's cut" => ["Apocalypse Now Director's Cut", 'Apocalypse Now'],
            'extended edition' => ['The Fellowship of the Ring Extended Edition', 'The Fellowship of the Ring'],
            'remastered' => ['Akira Remastered', 'Akira'],
            'criterion' => ['Brazil Criterion Collection', 'Brazil'],
            'nothing to strip' => ['The Goonies', 'The Goonies'],
        ];
    }

    #[DataProvider('releases')]
    public function test_it_strips_edition_wording(string $given, string $expected): void
    {
        $this->assertSame($expected, $this->clean($given));
    }

    /**
     * The stripping runs to the end of the string, so a bare-word list would
     * destroy real titles. These must survive untouched.
     *
     * @return array<string, array{0: string}>
     */
    public static function realTitles(): array
    {
        return [
            'Special' => ['The Special'],
            'Ultimate' => ['Ultimate Avengers'],
            'Final' => ['The Final Girls'],
            'Cut' => ['The Cut'],
            'Limited' => ['Limited Partners'],
            'Collection' => ['The Bling Ring'],
        ];
    }

    #[DataProvider('realTitles')]
    public function test_it_leaves_real_titles_alone(string $title): void
    {
        $this->assertSame($title, $this->clean($title));
    }

    public function test_a_title_that_is_only_edition_wording_is_kept(): void
    {
        // Better to search something hopeless than nothing at all.
        $this->assertSame('Remastered', $this->clean('Remastered'));
    }
}
