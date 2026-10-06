<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace Tests\Feature;

use App\Enums\MediaItemType;
use App\Enums\VersionVerdict;
use App\Models\MediaItem;
use App\Models\MediaProbe;
use App\Models\User;
use App\Services\Versions\EditionKey;
use App\Services\Versions\VersionGrouper;
use App\Services\Versions\WorkKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Versions are kept, not merged (#489).
 *
 * The rule: *"If Spotify has 15 versions of a song for an artist, we should
 * too."* So **keep both is the default** and the burden of proof is on calling
 * something a duplicate. Only `same work + same edition + same quality` is a
 * duplicate; everything else stays on disk and stays browsable.
 *
 * The cases that matter most here are the ones that must **not** be called
 * duplicates, because that is the loss the user was complaining about: ~29% of
 * identifier-matched pairs on this library were the same recording on a
 * different release, and the owner was asked to adjudicate pairs that both
 * belonged.
 */
class VersionGroupingTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
    }

    /* ------------------------------------------------------ work keys --- */

    public function test_a_recording_id_is_the_work_key(): void
    {
        // The *recording*, not the release: one performance appearing on an
        // album, a single and a compilation is the case this exists for.
        $item = $this->track('Song', recordingId: 'badf0c46-e52b-4534-b59b-0aea31d32d61');

        $this->assertSame(
            'mb:recording:badf0c46-e52b-4534-b59b-0aea31d32d61',
            app(WorkKey::class)->for($item),
        );
    }

    public function test_an_isrc_is_normalised_into_the_work_key(): void
    {
        // Taggers write ISRCs with and without hyphens; the same recording
        // must not get two keys because of punctuation.
        $withHyphens = $this->track('A', isrc: 'US-AT2-18-00165');
        $without = $this->track('B', isrc: 'USAT21800165');

        $this->assertSame(
            app(WorkKey::class)->for($withHyphens),
            app(WorkKey::class)->for($without),
        );
    }

    public function test_an_unidentified_file_has_no_work_key(): void
    {
        // Null is a real answer and the common one mid-import. A key derived
        // from the title would group two different songs that share a name,
        // which is exactly the false-duplicate problem.
        $this->assertNull(app(WorkKey::class)->for($this->track('Unknown')));
    }

    /* --------------------------------------------------- edition keys --- */

    /** @return array<string, array{0: string, 1: ?string}> */
    public static function editions(): array
    {
        return [
            // Real suffixes from this library.
            'single version' => ['Single Version', 'single'],
            'radio edit' => ['Radio Edit', 'radio_edit'],
            'acoustic' => ['Acoustic', 'acoustic'],
            'live' => ['Live', 'live'],
            'deluxe edition beats edition' => ['Deluxe Edition', 'deluxe'],
            // A year must survive: a 2009 and a 2012 remaster are different
            // masters, and merging them loses one.
            'remaster keeps its year' => ['Remastered 2009', 'remaster_2009'],
            'year first' => ['2004 Remaster', 'remaster_2004'],
            // "Album version" names the plain release explicitly.
            'album version is the plain release' => ['Album Version', null],
            // An unknown marker is kept rather than discarded -- it still
            // distinguishes two files.
            'unknown marker is kept' => ['Rough Trade Version', 'rough_trade_version'],
        ];
    }

    #[DataProvider('editions')]
    public function test_edition_markers_are_read(string $phrase, ?string $expected): void
    {
        $this->assertSame($expected, app(EditionKey::class)->keyFor($phrase));
    }

    public function test_two_remasters_from_different_years_are_different_editions(): void
    {
        // The single most important edition case: merging them would delete a
        // master the user chose to keep.
        $this->assertNotSame(
            app(EditionKey::class)->keyFor('Remastered 2009'),
            app(EditionKey::class)->keyFor('Remastered 2012'),
        );
    }

    public function test_an_artist_name_in_the_title_is_not_an_edition(): void
    {
        // 126 of this library's 772 suffixed titles are the artist's own name
        // written into the title (#489). Reading those as editions would
        // invent 126 editions that do not exist.
        $item = $this->track('Feel It Still - Portugal The Man', artist: 'Portugal. The Man');

        $this->assertNull(app(EditionKey::class)->for($item));
    }

    /* ------------------------------------------------------- verdicts --- */

    public function test_the_same_recording_on_two_albums_is_a_version_not_a_duplicate(): void
    {
        // What the user was complaining about. The album cut and the
        // greatest-hits copy are both worth keeping.
        $mbid = 'badf0c46-e52b-4534-b59b-0aea31d32d61';

        $album = $this->classified($this->track('Song', recordingId: $mbid, album: 'The Album'));
        $comp = $this->classified($this->track('Song - Remastered 2012', recordingId: $mbid, album: 'Greatest Hits'));

        $verdict = app(VersionGrouper::class)->compare($album, $comp);

        $this->assertSame(VersionVerdict::Version, $verdict);
        $this->assertTrue($verdict->keepsBoth());
        $this->assertFalse($verdict->needsReview(), 'A version is information, not a question.');
    }

    public function test_the_same_work_same_edition_same_quality_is_a_duplicate(): void
    {
        // The only real duplicate -- and still a review item rather than an
        // automatic delete, because which copy to keep depends on things the
        // code cannot see.
        $mbid = 'badf0c46-e52b-4534-b59b-0aea31d32d61';

        $a = $this->classified($this->track('Song', recordingId: $mbid));
        $b = $this->classified($this->track('Song', recordingId: $mbid));

        $verdict = app(VersionGrouper::class)->compare($a, $b);

        $this->assertSame(VersionVerdict::Duplicate, $verdict);
        $this->assertTrue($verdict->needsReview());
    }

    public function test_different_recordings_are_unrelated_however_alike_the_titles(): void
    {
        // Two different MBIDs means two different performances, whatever the
        // titles say. Guessing from titles is what produced the false
        // duplicates in the first place.
        $a = $this->classified($this->track('Song', recordingId: 'aaaaaaaa-0000-0000-0000-000000000001'));
        $b = $this->classified($this->track('Song', recordingId: 'bbbbbbbb-0000-0000-0000-000000000002'));

        $this->assertSame(VersionVerdict::Unrelated, app(VersionGrouper::class)->compare($a, $b));
    }

    public function test_a_stale_hash_does_not_make_two_files_identical(): void
    {
        // 2,298 pairs in this library share a hash across different sizes --
        // the S-346 stale-fingerprint condition (#491). Trusting the hash
        // alone called a 5,425,567-byte file and a 5,381,369-byte file
        // identical copies of each other.
        $mbid = 'badf0c46-e52b-4534-b59b-0aea31d32d61';

        $a = $this->track('Song', recordingId: $mbid);
        $b = $this->track('Song', recordingId: $mbid);

        $a->forceFill(['content_hash' => 'f51430cb81a7', 'file_size' => 5_425_567])->saveQuietly();
        $b->forceFill(['content_hash' => 'f51430cb81a7', 'file_size' => 5_381_369])->saveQuietly();

        $verdict = app(VersionGrouper::class)->compare(
            $this->classified($a->fresh()),
            $this->classified($b->fresh()),
        );

        $this->assertNotSame(
            VersionVerdict::IdenticalCopy,
            $verdict,
            'A hash that contradicts the size is unusable, not proof.',
        );
    }

    public function test_a_matching_hash_with_matching_sizes_is_an_identical_copy(): void
    {
        // The other half: a trustworthy hash still does its job.
        $a = $this->track('Song');
        $b = $this->track('Song');

        foreach ([$a, $b] as $item) {
            $item->forceFill(['content_hash' => 'abc123', 'file_size' => 1_000])->saveQuietly();
        }

        $this->assertSame(
            VersionVerdict::IdenticalCopy,
            app(VersionGrouper::class)->compare($a->fresh(), $b->fresh()),
        );
    }

    public function test_the_same_edition_at_different_resolutions_is_a_quality_variant(): void
    {
        // Worth telling the user about, but their call: a 1080p copy that
        // plays on the kitchen TV is not made useless by a 4K one existing.
        $a = $this->classified($this->movie('Film', tmdbId: 308266));
        $b = $this->classified($this->movie('Film', tmdbId: 308266));

        $this->probe($a, height: 2160);
        $this->probe($b, height: 1080);

        $verdict = app(VersionGrouper::class)->compare($a->fresh(), $b->fresh());

        $this->assertSame(VersionVerdict::QualityVariant, $verdict);
        $this->assertTrue($verdict->keepsBoth());
        $this->assertFalse($verdict->needsReview());
    }

    public function test_a_cropped_aspect_ratio_is_not_a_different_quality_tier(): void
    {
        // 1920x1038 and 1920x1080 are both "1080p" to anyone choosing between
        // copies. Treating them as different tiers would call every pair a
        // quality variant and never a duplicate.
        $a = $this->classified($this->movie('Film', tmdbId: 308266));
        $b = $this->classified($this->movie('Film', tmdbId: 308266));

        $this->probe($a, height: 1080);
        $this->probe($b, height: 1038);

        $this->assertSame(
            VersionVerdict::Duplicate,
            app(VersionGrouper::class)->compare($a->fresh(), $b->fresh()),
        );
    }

    /* -------------------------------------------------------- grouping -- */

    public function test_every_version_is_listed_none_hidden(): void
    {
        // The user's instruction: versions are browsable, not stashed behind a
        // picker. The query must return all of them.
        $mbid = 'badf0c46-e52b-4534-b59b-0aea31d32d61';

        $plain = $this->classified($this->track('Song', recordingId: $mbid));
        $this->classified($this->track('Song - Live', recordingId: $mbid));
        $this->classified($this->track('Song - Acoustic', recordingId: $mbid));

        $this->assertCount(3, app(VersionGrouper::class)->versionsOf($plain->fresh()));
        $this->assertCount(2, app(VersionGrouper::class)->siblingsOf($plain->fresh()));
    }

    public function test_the_plain_release_is_elected_primary(): void
    {
        // What an ambiguous "play this song" resolves to. Somebody naming a
        // song usually means the album version, not the live take.
        $mbid = 'badf0c46-e52b-4534-b59b-0aea31d32d61';

        $live = $this->classified($this->track('Song - Live', recordingId: $mbid));
        $plain = $this->classified($this->track('Song', recordingId: $mbid));

        app(VersionGrouper::class)->electPrimary($plain->fresh());

        $this->assertTrue((bool) $plain->fresh()->is_primary_version);
        $this->assertFalse((bool) $live->fresh()->is_primary_version);
    }

    public function test_an_only_copy_is_its_own_primary(): void
    {
        $item = $this->classified($this->track('Song', recordingId: 'badf0c46-e52b-4534-b59b-0aea31d32d61'));

        app(VersionGrouper::class)->electPrimary($item->fresh());

        $this->assertTrue((bool) $item->fresh()->is_primary_version);
    }

    /* --------------------------------------------------------- helpers -- */

    private function classified(MediaItem $item): MediaItem
    {
        return app(VersionGrouper::class)->classify($item)->fresh(['musicMetadata', 'movieMetadata', 'probe']);
    }

    private function track(
        string $title,
        ?string $recordingId = null,
        ?string $isrc = null,
        ?string $album = null,
        ?string $artist = 'Someone',
    ): MediaItem {
        $item = MediaItem::create([
            'user_id' => $this->user->id,
            'type' => MediaItemType::Music,
            'title' => $title,
            'owned' => true,
        ]);

        $item->musicMetadata()->create([
            'artist' => $artist,
            'primary_artist' => $artist,
            'album' => $album,
            'musicbrainz_recording_id' => $recordingId,
            'isrc' => $isrc,
        ]);

        return $item->fresh(['musicMetadata']);
    }

    private function movie(string $title, int $tmdbId): MediaItem
    {
        $item = MediaItem::create([
            'user_id' => $this->user->id,
            'type' => MediaItemType::Movie,
            'title' => $title,
            'owned' => true,
        ]);

        $item->movieMetadata()->create(['tmdb_id' => $tmdbId, 'release_year' => 2016]);

        return $item->fresh(['movieMetadata']);
    }

    private function probe(MediaItem $item, int $height): void
    {
        MediaProbe::create([
            'media_item_id' => $item->id,
            'probed_at' => now(),
            'video_codec' => 'h264',
            'width' => (int) round($height * 16 / 9),
            'height' => $height,
            'audio_streams' => [['codec' => 'aac', 'channels' => 2]],
        ]);
    }
}
