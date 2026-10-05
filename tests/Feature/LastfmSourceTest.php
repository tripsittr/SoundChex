<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace Tests\Feature;

use App\Enums\MediaItemType;
use App\Models\MediaItem;
use App\Models\MusicMetadata;
use App\Models\User;
use App\Services\Metadata\Sources\Music\Lastfm;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Last.fm, for the identifiers more than the tags.
 *
 * On the real library 10 tracks of 8,313 carry an ISRC, 28 a MusicBrainz
 * recording id and none a fingerprint — so every duplicate decision and every
 * exact lookup falls back to matching tag text. Last.fm answers a plain
 * artist-and-title query with MusicBrainz ids, which is the one cheap way to
 * raise that coverage without a fingerprinting key.
 *
 * The tags are the advertised reason and the part that needs guarding: they are
 * a folksonomy, and "seen live" written into the genre facet is what makes a
 * genre list untrustworthy.
 */
class LastfmSourceTest extends TestCase
{
    use RefreshDatabase;

    private Lastfm $source;

    private User $user;

    private static int $n = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->source = app(Lastfm::class);
        $this->user = User::factory()->create();

        app(SettingsService::class)->set('lastfm_api_key', 'test-key');
    }

    /* ------------------------------------------------------- supports() --- */

    public function test_it_declines_without_a_key(): void
    {
        app(SettingsService::class)->set('lastfm_api_key', '');

        $this->assertFalse($this->source->supports($this->track('A-Punk', 'Vampire Weekend')));
    }

    public function test_it_declines_without_an_artist_to_search_with(): void
    {
        $this->assertFalse($this->source->supports($this->track('A-Punk', '')));
    }

    public function test_it_declines_for_anything_that_is_not_music(): void
    {
        $film = MediaItem::create([
            'user_id' => $this->user->id,
            'type' => MediaItemType::Movie,
            'title' => 'Heat',
            'file_path' => 'media/library/heat.mkv',
            'owned' => true,
        ]);

        $this->assertFalse($this->source->supports($film));
    }

    public function test_it_supports_a_tagged_music_file_with_a_key(): void
    {
        $this->assertTrue($this->source->supports($this->track('A-Punk', 'Vampire Weekend')));
    }

    /* ----------------------------------------------------- identifiers --- */

    public function test_it_fills_the_musicbrainz_ids_when_they_are_missing(): void
    {
        $item = $this->track('A-Punk', 'Vampire Weekend');

        $this->fake([
            'mbid' => '4f3b2a1c-5d6e-4a7b-8c9d-0e1f2a3b4c5d',
            'album' => ['mbid' => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee'],
        ]);

        $this->source->enrich($item);

        $meta = $item->fresh()->musicMetadata;

        $this->assertSame('4f3b2a1c-5d6e-4a7b-8c9d-0e1f2a3b4c5d', $meta->musicbrainz_recording_id);
        $this->assertSame('aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee', $meta->musicbrainz_release_id);
    }

    /** A better source ran earlier; its answer stands. */
    public function test_it_does_not_overwrite_an_existing_identifier(): void
    {
        $item = $this->track('A-Punk', 'Vampire Weekend');
        $item->musicMetadata->forceFill([
            'musicbrainz_recording_id' => '11111111-2222-3333-4444-555555555555',
        ])->saveQuietly();

        $this->fake(['mbid' => '4f3b2a1c-5d6e-4a7b-8c9d-0e1f2a3b4c5d']);

        $this->source->enrich($item->fresh());

        $this->assertSame(
            '11111111-2222-3333-4444-555555555555',
            $item->fresh()->musicMetadata->musicbrainz_recording_id
        );
    }

    /**
     * Anything that is not a UUID is refused.
     *
     * An id in that column is used afterwards as a lookup key and as a
     * duplicate signal, so a malformed one would quietly pair two unrelated
     * tracks. Last.fm returns an empty string rather than omitting the field.
     */
    public function test_it_refuses_an_identifier_that_is_not_a_uuid(): void
    {
        $item = $this->track('A-Punk', 'Vampire Weekend');

        $this->fake(['mbid' => '', 'album' => ['mbid' => 'not-a-uuid']]);

        $this->source->enrich($item);

        $meta = $item->fresh()->musicMetadata;

        $this->assertNull($meta->musicbrainz_recording_id);
        $this->assertNull($meta->musicbrainz_release_id);
    }

    /* ----------------------------------------------------------- cover --- */

    public function test_it_takes_the_largest_cover_when_none_is_set(): void
    {
        $item = $this->track('A-Punk', 'Vampire Weekend');

        $this->fake(['album' => ['image' => [
            ['#text' => 'https://example.test/small.png', 'size' => 'small'],
            ['#text' => 'https://example.test/extralarge.png', 'size' => 'extralarge'],
        ]]]);

        $this->source->enrich($item);

        $this->assertSame('https://example.test/extralarge.png', $item->fresh()->cover_image_url);
    }

    public function test_it_leaves_an_existing_cover_alone(): void
    {
        $item = $this->track('A-Punk', 'Vampire Weekend');
        $item->forceFill(['cover_image_url' => 'https://example.test/already.png'])->saveQuietly();

        $this->fake(['album' => ['image' => [['#text' => 'https://example.test/lastfm.png', 'size' => 'large']]]]);

        $this->source->enrich($item->fresh());

        $this->assertSame('https://example.test/already.png', $item->fresh()->cover_image_url);
    }

    /* ------------------------------------------------------------ tags --- */

    /**
     * The folksonomy is filtered, and this is the point of the filter.
     *
     * Every rejected tag below is one Last.fm really returns for popular
     * tracks. Any of them reaching the genre facet makes it untrustworthy.
     */
    public function test_it_keeps_genres_and_rejects_everything_else(): void
    {
        $item = $this->track('A-Punk', 'Vampire Weekend');

        $this->fake(['toptags' => ['tag' => [
            ['name' => 'seen live'],
            ['name' => 'favourites'],
            ['name' => '00s'],
            ['name' => 'british'],
            ['name' => 'female vocalists'],
            ['name' => 'albums i own'],
            ['name' => 'indie rock'],
            ['name' => 'awesome'],
            ['name' => 'post-punk'],
        ]]]);

        $this->source->enrich($item);

        $this->assertSame(
            ['Indie Rock', 'Post-Punk'],
            $item->fresh()->tags()->where('type', 'genre')->pluck('value')->sort()->values()->all()
        );
    }

    /** At most three, so one noisy track cannot fill the facet. */
    public function test_it_takes_no_more_than_three_genres(): void
    {
        $item = $this->track('A-Punk', 'Vampire Weekend');

        $this->fake(['toptags' => ['tag' => [
            ['name' => 'indie rock'],
            ['name' => 'post-punk'],
            ['name' => 'shoegaze'],
            ['name' => 'dream pop'],
            ['name' => 'noise rock'],
        ]]]);

        $this->source->enrich($item);

        $this->assertCount(3, $item->fresh()->tags()->where('type', 'genre')->get());
    }

    /** An alias still canonicalises, the same as every other source. */
    public function test_an_alias_is_canonicalised(): void
    {
        $item = $this->track('Juicy', 'The Notorious B.I.G.');

        $this->fake(['toptags' => ['tag' => [['name' => 'hip hop']]]]);

        $this->source->enrich($item);

        $this->assertSame(
            ['Hip-Hop'],
            $item->fresh()->tags()->where('type', 'genre')->pluck('value')->all()
        );
    }

    public function test_it_does_not_duplicate_a_genre_another_source_already_wrote(): void
    {
        $item = $this->track('A-Punk', 'Vampire Weekend');
        $item->tags()->create(['type' => 'genre', 'value' => 'Indie Rock', 'source' => 'api']);

        $this->fake(['toptags' => ['tag' => [['name' => 'indie rock'], ['name' => 'post-punk']]]]);

        $this->source->enrich($item->fresh());

        $this->assertSame(
            ['Indie Rock', 'Post-Punk'],
            $item->fresh()->tags()->where('type', 'genre')->pluck('value')->sort()->values()->all()
        );
    }

    /* --------------------------------------------------------- failure --- */

    /** An unknown track is an answer, and nothing is written. */
    public function test_a_track_last_fm_does_not_know_changes_nothing(): void
    {
        $item = $this->track('Nonexistent', 'Nobody');

        Http::fake(['ws.audioscrobbler.com/*' => Http::response(['error' => 6, 'message' => 'Track not found'], 404)]);

        $this->source->enrich($item);

        $this->assertNull($item->fresh()->musicMetadata->musicbrainz_recording_id);
        $this->assertCount(0, $item->fresh()->tags);
    }

    /** A failed request is not an answer; nothing is written and nothing throws. */
    public function test_a_failed_request_is_survived(): void
    {
        $item = $this->track('A-Punk', 'Vampire Weekend');

        Http::fake(['ws.audioscrobbler.com/*' => Http::response('gateway timeout', 504)]);

        $this->source->enrich($item);

        $this->assertNull($item->fresh()->musicMetadata->musicbrainz_recording_id);
    }

    /**
     * The same question is asked once, and the key is not part of the question.
     *
     * Both halves in one test, because the second is only observable through
     * the first: if the api key were part of the cache key, changing it would
     * miss the cache and send a second request.
     */
    public function test_one_request_serves_the_same_track_whatever_the_key(): void
    {
        $this->fake(['mbid' => '4f3b2a1c-5d6e-4a7b-8c9d-0e1f2a3b4c5d']);

        $this->source->enrich($this->track('A-Punk', 'Vampire Weekend'));

        // A different install, the same track.
        app(SettingsService::class)->set('lastfm_api_key', 'a-completely-different-key');

        $this->source->enrich($this->track('A-Punk', 'Vampire Weekend'));

        Http::assertSentCount(1);
    }

    /** And a different track is a different question. */
    public function test_a_different_track_is_asked_separately(): void
    {
        $this->fake(['mbid' => '4f3b2a1c-5d6e-4a7b-8c9d-0e1f2a3b4c5d']);

        $this->source->enrich($this->track('A-Punk', 'Vampire Weekend'));
        $this->source->enrich($this->track('Sun', 'Two Door Cinema Club'));

        Http::assertSentCount(2);
    }

    /** @param array<string, mixed> $track */
    private function fake(array $track): void
    {
        Http::fake([
            'ws.audioscrobbler.com/*' => Http::response(['track' => $track + ['name' => 'A-Punk']]),
        ]);
    }

    private function track(string $title, string $artist): MediaItem
    {
        self::$n++;

        $item = MediaItem::create([
            'user_id' => $this->user->id,
            'type' => MediaItemType::Music,
            'title' => $title,
            'file_path' => 'media/library/lastfm-'.self::$n.'.flac',
            'owned' => true,
        ]);

        MusicMetadata::create([
            'media_item_id' => $item->id,
            'artist' => $artist,
            'primary_artist' => $artist,
        ]);

        return $item->fresh();
    }
}
