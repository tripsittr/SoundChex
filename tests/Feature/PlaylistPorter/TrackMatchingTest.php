<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace Tests\Feature\PlaylistPorter;

use App\Enums\MediaItemType;
use App\Models\MediaItem;
use App\Models\Profile;
use App\Models\User;
use App\Services\CurrentProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use SoundChex\PlaylistPorter\Services\ImportedTrack;
use SoundChex\PlaylistPorter\Services\TrackMatch;
use SoundChex\PlaylistPorter\Services\TrackMatcher;
use Tests\TestCase;

/**
 * How a playlist track finds its library item (S-324).
 *
 * The first matcher required the album to be equal and the length within three
 * seconds, and compared the artist against `primary_artist` alone. On a real
 * 530-track import that rejected 83 songs the library actually held. Each case
 * below is one of those, so the strictness cannot come back unnoticed.
 */
class TrackMatchingTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $root = dirname(base_path()).'/Plugins/soundchex-playlist-porter';

        if (! is_dir($root)) {
            $this->markTestSkipped('Playlist Porter plugin source not available.');
        }

        foreach ([
            '/src/Services/ImportedTrack.php',
            '/src/Services/TrackMatch.php',
            '/src/Services/TrackMatcher.php',
        ] as $file) {
            require_once $root.$file;
        }

        $this->user = User::factory()->create();
        $profile = Profile::create(['user_id' => $this->user->id, 'name' => 'Owner', 'is_owner' => true]);
        $this->actingAs($this->user);
        app(CurrentProfile::class)->switchTo($profile->id);
    }

    public function test_a_collaboration_credit_matches_the_same_credit_in_the_library(): void
    {
        // The library stores the whole credit but names the lead as primary —
        // the old matcher coalesced to the primary and so could never match.
        $this->libraryTrack(
            title: 'Up Down',
            artist: 'Morgan Wallen, Florida Georgia Line',
            primaryArtist: 'Morgan Wallen',
        );

        $match = app(TrackMatcher::class)->best(new ImportedTrack(
            title: 'Up Down (feat. Florida Georgia Line)',
            artist: 'Morgan Wallen, Florida Georgia Line',
        ));

        $this->assertNotNull($match, 'a collaboration must not be lost');
        $this->assertSame('Up Down', $match->item->title);
    }

    public function test_the_same_song_on_a_different_album_still_matches_but_is_flagged(): void
    {
        $this->libraryTrack(
            title: 'Fishin\' in the Dark',
            artist: 'Nitty Gritty Dirt Band',
            album: 'Rhino Hi-Five: Nitty Gritty Dirt Band',
        );

        $match = app(TrackMatcher::class)->best(new ImportedTrack(
            title: 'Fishin\' in the Dark',
            artist: 'Nitty Gritty Dirt Band',
            album: 'Hold On',
        ));

        $this->assertNotNull($match, 'a compilation copy is still the song');
        $this->assertTrue($match->needsReview(), 'but the release disagreed, so flag it');
        $this->assertStringContainsString('different release', $match->reason);
    }

    public function test_a_remaster_a_few_seconds_long_still_matches(): void
    {
        $this->libraryTrack('Toes', 'Zac Brown Band', album: 'The Foundation', durationMs: 251794);

        $match = app(TrackMatcher::class)->best(new ImportedTrack(
            title: 'Toes', artist: 'Zac Brown Band', album: 'The Foundation', durationMs: 262773,
        ));

        $this->assertNotNull($match);
        $this->assertTrue($match->needsReview());
    }

    public function test_an_identifier_match_is_certain_and_needs_no_review(): void
    {
        $item = $this->libraryTrack('Any Title At All', 'Any Artist');
        $item->musicMetadata->update(['isrc' => 'GBUM71029604']);

        $match = app(TrackMatcher::class)->best(new ImportedTrack(
            title: 'A Completely Different Title', artist: 'Someone Else', isrc: 'GBUM71029604',
        ));

        $this->assertNotNull($match);
        $this->assertSame(TrackMatch::CERTAIN, $match->confidence);
        $this->assertFalse($match->needsReview(), 'an ISRC is proof');
    }

    public function test_a_clean_title_and_artist_match_is_not_sent_to_review(): void
    {
        $this->libraryTrack('Simple', 'Florida Georgia Line');

        $match = app(TrackMatcher::class)->best(
            new ImportedTrack(title: 'Simple', artist: 'Florida Georgia Line'),
        );

        $this->assertNotNull($match);
        $this->assertFalse(
            $match->needsReview(),
            'flagging every tag-based match would bury the ones that matter',
        );
    }

    public function test_a_different_artist_is_never_matched(): void
    {
        $this->libraryTrack('Toes', 'Zac Brown Band');

        $this->assertNull(
            app(TrackMatcher::class)->best(new ImportedTrack(title: 'Toes', artist: 'Someone Entirely Else')),
            'a shared title is not evidence on its own',
        );
    }

    public function test_a_title_opening_with_punctuation_is_found(): void
    {
        // The database narrowing once used the first characters of the
        // normalised title, which strips leading punctuation — so these never
        // matched the stored title.
        $this->libraryTrack("(There's) No Gettin' Over Me", 'Ronnie Milsap');

        $this->assertNotNull(app(TrackMatcher::class)->best(
            new ImportedTrack(title: "(There's) No Gettin' Over Me", artist: 'Ronnie Milsap'),
        ));
    }

    public function test_a_curly_apostrophe_matches_a_straight_one(): void
    {
        $this->libraryTrack('Whiskey’d My Way', 'Morgan Wallen');

        $this->assertNotNull(app(TrackMatcher::class)->best(
            new ImportedTrack(title: "Whiskey'd My Way", artist: 'Morgan Wallen'),
        ));
    }

    public function test_it_offers_ranked_candidates_for_the_review_list(): void
    {
        // Two plausible copies of the same song: the album the playlist names,
        // and a greatest-hits cut of near-identical length.
        $this->libraryTrack('Toes', 'Zac Brown Band', album: 'The Foundation', durationMs: 251794);
        $this->libraryTrack('Toes', 'Zac Brown Band', album: 'Greatest Hits', durationMs: 253000);

        $candidates = app(TrackMatcher::class)->candidatesFor(new ImportedTrack(
            title: 'Toes', artist: 'Zac Brown Band', album: 'The Foundation', durationMs: 251794,
        ));

        $this->assertCount(2, $candidates);
        $this->assertSame(
            'The Foundation',
            $candidates->first()->item->musicMetadata->album,
            'the release the playlist named ranks first',
        );
    }

    public function test_a_copy_that_disagrees_on_both_release_and_length_is_not_offered(): void
    {
        // A different album *and* minutes longer is probably a live cut or a
        // different recording; offering it would make the review list noise.
        $this->libraryTrack('Toes', 'Zac Brown Band', album: 'Live At Red Rocks', durationMs: 400000);

        $this->assertCount(0, app(TrackMatcher::class)->candidatesFor(new ImportedTrack(
            title: 'Toes', artist: 'Zac Brown Band', album: 'The Foundation', durationMs: 251794,
        )));
    }

    private function libraryTrack(
        string $title,
        string $artist,
        ?string $album = null,
        ?int $durationMs = null,
        ?string $primaryArtist = null,
    ): MediaItem {
        $item = MediaItem::create([
            'user_id' => $this->user->id,
            'type' => MediaItemType::Music,
            'title' => $title,
            'file_path' => 'music/'.md5($title.$artist.$album).'.mp3',
            'owned' => true,
        ]);

        $item->musicMetadata()->create([
            'artist' => $artist,
            'primary_artist' => $primaryArtist ?? $artist,
            'album' => $album,
            'duration_ms' => $durationMs,
        ]);

        return $item->fresh();
    }
}
