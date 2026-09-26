<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace Tests\Feature;

use App\Enums\MediaItemType;
use App\Enums\ProcessingStatus;
use App\Models\MediaItem;
use App\Models\Person;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Cast and crew over the API (S-412).
 *
 * Deliberately not part of the library sync: 8,323 items in a real library
 * carry credits, and mirroring them all so a detail page can show a handful
 * would make every device download every actor of every film.
 */
class MediaDetailsApiTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
    }

    private function film(): MediaItem
    {
        return MediaItem::unresolved()->create([
            'user_id' => $this->user->id,
            'title' => 'A Film',
            'type' => MediaItemType::Movie,
            'file_path' => 'movies/a-film.mkv',
            'processing_status' => ProcessingStatus::Complete,
        ]);
    }

    public function test_it_splits_cast_from_crew(): void
    {
        $film = $this->film();

        $actor = Person::create(['name' => 'An Actor']);
        $director = Person::create(['name' => 'A Director']);

        $film->people()->attach($actor->id, ['role' => 'actor', 'character' => 'Someone', 'sort_order' => 1]);
        $film->people()->attach($director->id, ['role' => 'director', 'sort_order' => 2]);

        $response = $this->actingAs($this->user)->getJson("/api/v1/items/{$film->id}/details");

        $response->assertOk();
        $response->assertJsonPath('cast.0.name', 'An Actor');
        $response->assertJsonPath('cast.0.character', 'Someone');
        $response->assertJsonPath('crew.0.name', 'A Director');
        $response->assertJsonPath('crew.0.role', 'director');
    }

    public function test_it_keeps_billing_order(): void
    {
        // Cast order is meaningful — the lead is first, not whoever was
        // inserted first.
        $film = $this->film();

        $second = Person::create(['name' => 'Second Billed']);
        $first = Person::create(['name' => 'First Billed']);

        $film->people()->attach($second->id, ['role' => 'actor', 'sort_order' => 2]);
        $film->people()->attach($first->id, ['role' => 'actor', 'sort_order' => 1]);

        $this->actingAs($this->user)
            ->getJson("/api/v1/items/{$film->id}/details")
            ->assertJsonPath('cast.0.name', 'First Billed');
    }

    public function test_an_item_with_no_credits_returns_empty_lists(): void
    {
        $film = $this->film();

        $this->actingAs($this->user)
            ->getJson("/api/v1/items/{$film->id}/details")
            ->assertOk()
            ->assertJsonPath('cast', [])
            ->assertJsonPath('crew', []);
    }

    public function test_it_requires_authentication(): void
    {
        $film = $this->film();

        $this->getJson("/api/v1/items/{$film->id}/details")->assertUnauthorized();
    }

    public function test_the_movie_payload_carries_the_wider_metadata(): void
    {
        $film = $this->film();

        $film->movieMetadata()->create([
            'tagline' => 'A tagline',
            'studio' => 'A Studio',
            'imdb_rating' => 7.5,
            'release_year' => 2020,
        ]);

        $response = $this->actingAs($this->user)->getJson('/api/v1/library');

        $item = collect($response->json('items'))->firstWhere('id', $film->id);

        $this->assertSame('A tagline', $item['meta']['tagline']);
        $this->assertSame('A Studio', $item['meta']['studio']);
    }
}
