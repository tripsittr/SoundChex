<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace Tests\Unit;

use App\Services\EpisodeParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The parser decides whether a video is an episode or a film, so a false
 * positive files a movie into a season folder and a false negative leaves a
 * whole series in the inbox. Both directions are tested.
 */
class EpisodeParserTest extends TestCase
{
    private EpisodeParser $parser;

    protected function setUp(): void
    {
        $this->parser = new EpisodeParser();
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: int, 3: int}>
     */
    public static function episodes(): array
    {
        return [
            'standard release' => ['The.Bear.S01E02.1080p.WEB-DL.mkv', 'The Bear', 1, 2],
            'spaced' => ['Severance S02E07 HDR.mkv', 'Severance', 2, 7],
            'cross format' => ['Breaking Bad 1x02.mkv', 'Breaking Bad', 1, 2],
            'verbose' => ['The Office 2005 Season 1 Episode 2.mkv', 'The Office', 1, 2],
            'british series' => ['Sherlock.Series.2.Episode.3.mkv', 'Sherlock', 2, 3],
            'lowercase dotted' => ['Show.s01.e02.mkv', 'Show', 1, 2],
            'quality suffix' => ['Chernobyl S01E05 1080p x265.mkv', 'Chernobyl', 1, 5],
            'three digit episode' => ['Show S01E123.mkv', 'Show', 1, 123],

            // Two of these were catalogued as films. Unambiguous in the same
            // way S06E01 is, and simply absent from the pattern list.
            'season prefix with x' => ['The Simpsons S06X01 Homer.mkv', 'The Simpsons', 6, 1],
            'season prefix with x, lowercase' => ['Show.s02x11.mkv', 'Show', 2, 11],

            // The scanner passes a *basename*, so these arrive with the
            // extension already stripped. pathinfo() used to eat the episode
            // code off a dotted name, and every such file was catalogued as a
            // film — the shape most TV releases actually use.
            'dotted, no extension' => ['The.Bear.S01E01', 'The Bear', 1, 1],
            'dotted with extension' => ['The.Bear.S01E01.mkv', 'The Bear', 1, 1],
            'split code, no extension' => ['Show.S01.E02', 'Show', 1, 2],
            'split code with extension' => ['Show.S01.E02.mkv', 'Show', 1, 2],
        ];
    }

    #[DataProvider('episodes')]
    public function test_it_parses_episode_filenames(string $file, string $series, int $season, int $episode): void
    {
        $result = $this->parser->parse($file);

        $this->assertNotNull($result, "Expected {$file} to parse");
        $this->assertSame($series, $result['series']);
        $this->assertSame($season, $result['season']);
        $this->assertSame($episode, $result['episode']);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function nonEpisodes(): array
    {
        return [
            // A year is not a season/episode pair.
            'film with year' => ['Backrooms 2026 1080p WEB-DL x265.mkv'],
            'year in title' => ['Blade Runner 2049.mkv'],
            // 1920x1080 would match a naive NxNN pattern.
            'resolution' => ['Movie.1920x1080.mkv'],
            // No single correct destination.
            'multi episode dash' => ['The Bear S01E01-E02.mkv'],
            'multi episode joined' => ['The Bear S01E01E02.mkv'],
            // Season 0 is the specials convention.
            'special' => ['Show S00E01 Special.mkv'],
            'no numbering' => ['Some Documentary.mkv'],
        ];
    }

    #[DataProvider('nonEpisodes')]
    public function test_it_refuses_anything_it_cannot_place(string $file): void
    {
        $this->assertNull(
            $this->parser->parse($file),
            "Expected {$file} NOT to parse as an episode",
        );
    }

    /*
     * Being television and being filable are different questions.
     *
     * They were answered by one value, so everything parse() refused was
     * catalogued as a film — 48 season-zero Simpsons specials in the real
     * library, a third of everything filed there as a film.
     */

    /**
     * @return array<string, array{0: string}>
     */
    public static function televisionThatCannotBeFiled(): array
    {
        return [
            'season zero special' => ['The Simpsons S00E01 Good Night.mkv'],
            'season zero, two digits' => ['Show S00E48.mkv'],
            'multi episode dash' => ['The Bear S01E01-E02.mkv'],
            'multi episode joined' => ['The Bear S01E01E02.mkv'],
        ];
    }

    #[DataProvider('televisionThatCannotBeFiled')]
    public function test_it_knows_television_it_still_refuses_to_file(string $file): void
    {
        $this->assertTrue(
            $this->parser->isTelevision($file),
            "Expected {$file} to be recognised as television",
        );

        // And still refuses to file it, which is the behaviour being protected.
        $this->assertNull(
            $this->parser->parse($file),
            "Expected {$file} NOT to be filable",
        );
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function notTelevision(): array
    {
        return [
            'film with year' => ['Backrooms 2026 1080p WEB-DL x265.mkv'],
            'year in title' => ['Blade Runner 2049.mkv'],
            'resolution' => ['Movie.1920x1080.mkv'],
            'no numbering' => ['Some Documentary.mkv'],
            'real film from the library' => ['Ace.Ventura_.Pet.Detective.1994.1080p.BluRay.x264.YIFY.mp4'],
            'another real film' => ['Dumb And Dumber 1994 1080p BluRay HEVC x265 5.1 BONE.mkv'],
        ];
    }

    #[DataProvider('notTelevision')]
    public function test_a_film_is_not_mistaken_for_television(string $file): void
    {
        // The other direction, and the more expensive one to get wrong: a film
        // typed as a show is a film that disappears out of the films.
        $this->assertFalse(
            $this->parser->isTelevision($file),
            "Expected {$file} NOT to be treated as television",
        );
    }

    public function test_a_special_keeps_its_own_code(): void
    {
        // All 48 specials were catalogued under the bare series name, so the
        // library held 48 rows called "The Simpsons" and nothing to tell them
        // apart.
        $marker = $this->parser->marker('The Simpsons S00E07 The Krusty Ad.mkv');

        $this->assertNotNull($marker);
        $this->assertSame('The Simpsons', $marker['series']);
        $this->assertSame(0, $marker['season']);
        $this->assertSame(7, $marker['episode']);
    }
}
