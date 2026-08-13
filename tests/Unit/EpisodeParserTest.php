<?php

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
}
