<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace Tests\Feature;

use App\Enums\MediaItemType;
use App\Enums\ProcessingStatus;
use App\Models\MediaItem;
use App\Models\MediaPlay;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * "Continue watching" over the API (S-414).
 *
 * The row that makes a home page feel like the person's own rather than a
 * catalogue. The web has had it for a long time; the apps could not build it
 * themselves because resume position lives in `media_plays` and the library
 * mirror does not carry it.
 */
class ContinueWatchingApiTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
    }

    private function film(string $title): MediaItem
    {
        return MediaItem::unresolved()->create([
            'user_id' => $this->user->id,
            'title' => $title,
            'type' => MediaItemType::Movie,
            'file_path' => 'movies/'.str($title)->slug().'.mkv',
            'processing_status' => ProcessingStatus::Complete,
        ]);
    }

    private function play(MediaItem $item, int $seconds, bool $completed = false): void
    {
        MediaPlay::create([
            'media_item_id' => $item->id,
            'user_id' => $this->user->id,
            'position_seconds' => $seconds,
            'completed' => $completed,
        ]);
    }

    public function test_it_returns_a_part_watched_film(): void
    {
        $started = $this->film('Half Watched');
        $this->play($started, seconds: 1800);

        $response = $this->actingAs($this->user)->getJson('/api/v1/library/continue');

        $response->assertOk();

        $ids = collect($response->json('watching'))->pluck('id');

        $this->assertTrue($ids->contains($started->id));
    }

    public function test_a_finished_film_is_not_offered_again(): void
    {
        $done = $this->film('Finished');
        $this->play($done, seconds: 7000, completed: true);

        $response = $this->actingAs($this->user)->getJson('/api/v1/library/continue');

        $this->assertFalse(
            collect($response->json('watching'))->pluck('id')->contains($done->id),
        );
    }

    /**
     * A few seconds in is a mis-tap, not a viewing. Offering it back as
     * "continue" is how a home page fills with things nobody watched.
     */
    public function test_a_film_barely_started_is_not_offered(): void
    {
        $glanced = $this->film('Ten Seconds In');
        $this->play($glanced, seconds: 10);

        $response = $this->actingAs($this->user)->getJson('/api/v1/library/continue');

        $this->assertFalse(
            collect($response->json('watching'))->pluck('id')->contains($glanced->id),
        );
    }

    public function test_an_unwatched_film_is_not_offered(): void
    {
        $never = $this->film('Never Opened');

        $response = $this->actingAs($this->user)->getJson('/api/v1/library/continue');

        $this->assertFalse(
            collect($response->json('watching'))->pluck('id')->contains($never->id),
        );
    }

    /**
     * Someone else's viewing is not yours. Two people share a login and each
     * has their own profile; a shared home page showing the other person's
     * half-finished films is a small privacy failure as well as a useless row.
     */
    public function test_another_users_progress_does_not_leak(): void
    {
        $theirs = $this->film('Their Film');

        $other = User::factory()->create();

        MediaPlay::create([
            'media_item_id' => $theirs->id,
            'user_id' => $other->id,
            'position_seconds' => 1800,
            'completed' => false,
        ]);

        $response = $this->actingAs($this->user)->getJson('/api/v1/library/continue');

        $this->assertFalse(
            collect($response->json('watching'))->pluck('id')->contains($theirs->id),
        );
    }

    public function test_it_requires_authentication(): void
    {
        $this->getJson('/api/v1/library/continue')->assertUnauthorized();
    }

    public function test_it_returns_both_shelves(): void
    {
        $response = $this->actingAs($this->user)->getJson('/api/v1/library/continue');

        $response->assertOk();
        $response->assertJsonStructure(['watching', 'reading']);
    }
}
