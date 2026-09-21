<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace Tests\Feature\Api;

use App\Enums\MediaItemType;
use App\Models\MediaItem;
use App\Models\Profile;
use App\Models\Subtitle;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The native app's subtitle endpoints (S-160): list a video's caption tracks,
 * and load one track's WebVTT, both token-authed and content-gated.
 */
class SubtitleApiTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Profile $owner;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $this->user = User::factory()->create();
        $this->owner = Profile::create([
            'user_id' => $this->user->id,
            'name' => 'Owner',
            'is_owner' => true,
        ]);
        Sanctum::actingAs($this->user, ['profile:'.$this->owner->id]);
    }

    public function test_it_lists_a_videos_subtitle_tracks(): void
    {
        $movie = $this->movie();
        $track = $this->track($movie, language: 'en', label: 'English');

        $this->getJson(route('api.items.subtitles', $movie))
            ->assertOk()
            ->assertJsonPath('subtitles.0.id', $track->id)
            ->assertJsonPath('subtitles.0.language', 'en')
            ->assertJsonPath('subtitles.0.url', route('api.items.subtitle', ['item' => $movie, 'subtitle' => $track]));
    }

    public function test_it_serves_a_tracks_webvtt(): void
    {
        $movie = $this->movie();
        $track = $this->track($movie);

        $this->get(route('api.items.subtitle', ['item' => $movie, 'subtitle' => $track]))
            ->assertOk()
            ->assertHeader('Content-Type', 'text/vtt; charset=UTF-8')
            ->assertSee('WEBVTT');
    }

    public function test_a_track_not_belonging_to_the_item_is_404(): void
    {
        $movie = $this->movie();
        $other = $this->movie();
        $foreign = $this->track($other);

        $this->get(route('api.items.subtitle', ['item' => $movie, 'subtitle' => $foreign]))
            ->assertNotFound();
    }

    public function test_music_has_no_subtitles_endpoint(): void
    {
        $song = MediaItem::create([
            'user_id' => $this->user->id,
            'type' => MediaItemType::Music,
            'title' => 'A Song',
            'file_path' => 'library/song.flac',
            'owned' => true,
        ]);

        $this->getJson(route('api.items.subtitles', $song))->assertNotFound();
    }

    public function test_a_capped_profile_cannot_read_subtitles_for_a_gated_film(): void
    {
        // A kids profile capped at PG cannot reach an R-rated film's subtitles —
        // that would leak the film and its dialogue past the gate.
        $capped = Profile::create([
            'user_id' => $this->user->id,
            'name' => 'Kid',
            'max_rating' => 'PG',
        ]);
        Sanctum::actingAs($this->user, ['profile:'.$capped->id]);

        $movie = $this->movie(rating: 'R');
        $track = $this->track($movie);

        $this->getJson(route('api.items.subtitles', $movie))->assertNotFound();
        $this->get(route('api.items.subtitle', ['item' => $movie, 'subtitle' => $track]))->assertNotFound();
    }

    private function movie(?string $rating = null): MediaItem
    {
        $movie = MediaItem::create([
            'user_id' => $this->user->id,
            'type' => MediaItemType::Movie,
            'title' => 'A Film',
            'file_path' => 'library/a-film.mkv',
            'owned' => true,
        ]);
        $movie->movieMetadata()->create(['release_year' => 2026, 'mpaa_rating' => $rating]);

        return $movie;
    }

    private function track(MediaItem $item, string $language = 'en', string $label = 'English'): Subtitle
    {
        $path = "subtitles/{$item->id}-{$language}.vtt";
        Storage::disk('local')->put($path, "WEBVTT\n\n00:00:01.000 --> 00:00:03.000\nHello.\n");

        return $item->subtitles()->create([
            'language' => $language,
            'label' => $label,
            'source' => Subtitle::SOURCE_SIDECAR,
            'path' => $path,
            'is_default' => true,
        ]);
    }
}
