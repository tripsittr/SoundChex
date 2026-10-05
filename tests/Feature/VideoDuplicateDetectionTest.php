<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace Tests\Feature;

use App\Enums\DuplicateMatch;
use App\Enums\DuplicateStatus;
use App\Enums\MediaItemType;
use App\Models\MediaItem;
use App\Models\MovieMetadata;
use App\Models\ShowMetadata;
use App\Models\User;
use App\Services\DuplicateDetector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Duplicate films and episodes, which were never looked for.
 *
 * Video had only the byte pass, and a second copy of a film almost never
 * matches byte for byte — it is a different rip, a different release, or a
 * download that stopped early. So the obvious cases were invisible, and they
 * were obvious: two rows both titled "War Dogs", both resolved to TMDB 308266,
 * one 18 MB and the other 1.8 GB. The same for every Simpsons episode fetched
 * twice, where the file names differed only by a "(2)".
 *
 * The fixtures here are those two real cases.
 */
class VideoDuplicateDetectionTest extends TestCase
{
    use RefreshDatabase;

    private DuplicateDetector $detector;

    private User $user;

    private static int $n = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->detector = app(DuplicateDetector::class);
        $this->user = User::factory()->create();
    }

    /* ---------------------------------------------------------- films --- */

    public function test_the_same_film_in_two_different_files_is_flagged(): void
    {
        $big = $this->film('War Dogs', tmdb: 308266, year: 2016, bytes: 'a full rip');
        $stub = $this->film('War Dogs', tmdb: 308266, year: 2016, bytes: 'a partial download');

        $found = $this->detector->check($stub);

        $this->assertNotNull($found, 'Two files of the same film must be found.');
        $this->assertSame($big->id, $found->id);
        $this->assertSame(DuplicateMatch::Tmdb, $stub->fresh()->duplicate_match);
        $this->assertSame(DuplicateStatus::Pending, $stub->fresh()->duplicate_status);
    }

    /**
     * Never deleted automatically.
     *
     * The files genuinely differ, and which copy to throw away is obvious to a
     * person looking at 18 MB against 1.8 GB and not to this code.
     */
    public function test_a_film_match_is_only_ever_offered_for_review(): void
    {
        $this->film('War Dogs', tmdb: 308266, year: 2016, bytes: 'a full rip');
        $stub = $this->film('War Dogs', tmdb: 308266, year: 2016, bytes: 'a partial download');

        $this->detector->check($stub);

        $this->assertTrue($stub->fresh()->duplicate_match->isContent());
        $this->assertFileExists($stub->fresh()->absoluteFilePath());
    }

    /** Two different films are not a pair, whatever else they share. */
    public function test_two_different_films_are_left_alone(): void
    {
        $this->film('Heat', tmdb: 949, year: 1995, bytes: 'one film');
        $other = $this->film('Casino', tmdb: 524, year: 1995, bytes: 'another film');

        $this->assertNull($this->detector->check($other));
    }

    /**
     * A shared title is not enough on its own.
     *
     * There are two films called "The Mummy", and they are not copies of each
     * other. The year is required, not preferred.
     */
    public function test_the_same_title_in_a_different_year_is_not_a_duplicate(): void
    {
        $this->film('The Mummy', tmdb: null, year: 1999, bytes: 'brendan fraser');
        $remake = $this->film('The Mummy', tmdb: null, year: 2017, bytes: 'tom cruise');

        $this->assertNull($this->detector->check($remake));
    }

    /** Without a provider id, title and year together will do — for review. */
    public function test_an_unidentified_film_matches_on_title_and_year(): void
    {
        $first = $this->film('Some Indie', tmdb: null, year: 2011, bytes: 'one encode');
        $second = $this->film('some indie', tmdb: null, year: 2011, bytes: 'another encode');

        $found = $this->detector->check($second);

        $this->assertNotNull($found);
        $this->assertSame($first->id, $found->id);
        $this->assertSame(DuplicateMatch::SameTitle, $second->fresh()->duplicate_match);
    }

    /* ------------------------------------------------------- episodes --- */

    public function test_the_same_episode_twice_is_flagged(): void
    {
        $series = $this->series('The Simpsons');

        $first = $this->episode($series, 'Bart of Darkness', 6, 1, 'one encode');
        $second = $this->episode($series, 'Bart of Darkness', 6, 1, 'another encode');

        $found = $this->detector->check($second);

        $this->assertNotNull($found, 'The same episode twice must be found.');
        $this->assertSame($first->id, $found->id);
        $this->assertSame(DuplicateMatch::Episode, $second->fresh()->duplicate_match);
    }

    public function test_different_episodes_of_one_series_are_not_duplicates(): void
    {
        $series = $this->series('The Simpsons');

        $this->episode($series, 'Bart of Darkness', 6, 1, 'episode one');
        $second = $this->episode($series, "Lisa's Rival", 6, 2, 'episode two');

        $this->assertNull($this->detector->check($second));
    }

    /**
     * The same numbering in a different series is not a pair.
     *
     * Every series has an S01E01. Matching on numbering alone would pair all of
     * them with each other.
     */
    public function test_the_same_numbering_in_another_series_is_not_a_duplicate(): void
    {
        $simpsons = $this->series('The Simpsons');
        $futurama = $this->series('Futurama');

        $this->episode($simpsons, 'Simpsons Roasting', 1, 1, 'simpsons pilot');
        $other = $this->episode($futurama, 'Space Pilot 3000', 1, 1, 'futurama pilot');

        $this->assertNull($this->detector->check($other));
    }

    /* ---------------------------------------------------------- setup --- */

    private function film(string $title, ?int $tmdb, int $year, string $bytes): MediaItem
    {
        $item = $this->row($title, MediaItemType::Movie, $bytes);

        MovieMetadata::create([
            'media_item_id' => $item->id,
            'tmdb_id' => $tmdb,
            'release_year' => $year,
        ]);

        return $item->fresh();
    }

    private function series(string $name): MediaItem
    {
        return $this->row($name, MediaItemType::Show, 'series row '.$name);
    }

    private function episode(MediaItem $series, string $title, int $season, int $number, string $bytes): MediaItem
    {
        $item = $this->row($title, MediaItemType::Show, $bytes);
        $item->forceFill(['parent_id' => $series->id])->saveQuietly();

        ShowMetadata::create([
            'media_item_id' => $item->id,
            'season_number' => $season,
            'episode_number' => $number,
            'episode_title' => $title,
        ]);

        return $item->fresh();
    }

    private function row(string $title, MediaItemType $type, string $bytes): MediaItem
    {
        self::$n++;

        $path = 'media/library/item-'.self::$n.'.mkv';
        Storage::disk('local')->put($path, $bytes);

        $item = MediaItem::create([
            'user_id' => $this->user->id,
            'type' => $type,
            'title' => $title,
            'file_path' => Storage::disk('local')->path($path),
            'owned' => true,
        ]);

        $this->detector->ensureHashed($item);

        return $item->fresh();
    }
}
