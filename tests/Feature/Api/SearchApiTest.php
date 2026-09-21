<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace Tests\Feature\Api;

use App\Enums\MediaItemType;
use App\Models\MediaItem;
use App\Models\Profile;
use App\Models\SubtitleCue;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The native app's search endpoint (S-292).
 *
 * Search collects items from several groups — titles, dialogue, book pages, and
 * each person's own items — and flattens them into one list the app renders and
 * plays. The dialogue/page/people groups used to load a partial item
 * (`id, title, type` only), so a track surfaced through one of them arrived with
 * no artist and — worse — no `file_path`, which is what `playable` is derived
 * from, so it would not play. These assert the flattened items come back whole.
 */
class SearchApiTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Profile $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->owner = Profile::create([
            'user_id' => $this->user->id,
            'name' => 'Owner',
            'is_owner' => true,
        ]);

        Sanctum::actingAs($this->user, ['profile:'.$this->owner->id]);
    }

    public function test_an_item_found_through_dialogue_comes_back_playable_with_metadata(): void
    {
        $movie = MediaItem::create([
            'user_id' => $this->user->id,
            'type' => MediaItemType::Movie,
            'title' => 'A Quiet Film',
            'file_path' => 'library/a-quiet-film.mkv',
            'owned' => true,
        ]);
        $movie->movieMetadata()->create(['release_year' => 2026, 'director' => 'A Director']);

        // Only reachable by its dialogue — the title does not contain the term.
        $this->cue($movie, 'the pumpernickel is over there');

        $response = $this->getJson(route('api.search', ['q' => 'pumpernickel']))
            ->assertOk();

        $item = collect($response->json('items'))->firstWhere('id', $movie->id);

        $this->assertNotNull($item, 'the dialogue-matched item is in the results');
        // The bug: a partial load left file_path out, so playable was false.
        $this->assertTrue($item['playable'], 'the item is playable (file_path was loaded)');
        // And its metadata is present (director is the movie subtitle).
        $this->assertSame('A Director', $item['subtitle']);
    }

    public function test_a_music_track_found_through_its_artist_carries_the_artist(): void
    {
        $track = MediaItem::create([
            'user_id' => $this->user->id,
            'type' => MediaItemType::Music,
            'title' => 'An Untitled Instrumental',
            'file_path' => 'library/track.flac',
            'owned' => true,
        ]);
        // The artist is on the metadata, and the term matches the artist, not the
        // title — so it is found through the item search's artist match.
        $track->musicMetadata()->create(['artist' => 'Zephyr Quartet']);

        $response = $this->getJson(route('api.search', ['q' => 'Zephyr Quartet']))
            ->assertOk();

        $item = collect($response->json('items'))->firstWhere('id', $track->id);

        $this->assertNotNull($item);
        $this->assertTrue($item['playable']);
        $this->assertSame('Zephyr Quartet', $item['subtitle']);
    }

    private function cue(MediaItem $item, string $text): void
    {
        $subtitle = $item->subtitles()->firstOrCreate(
            ['language' => 'en'],
            ['label' => 'en', 'source' => 'test', 'path' => 'subs/en.vtt'],
        );

        SubtitleCue::create([
            'subtitle_id' => $subtitle->id,
            'media_item_id' => $item->id,
            'start_seconds' => 10,
            'end_seconds' => 13,
            'text' => $text,
        ]);
    }
}
