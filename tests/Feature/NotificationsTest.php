<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace Tests\Feature;

use App\Models\MediaItem;
use App\Models\Notification;
use App\Models\Profile;
use App\Models\User;
use App\Enums\MediaItemType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Events worth telling someone about.
 *
 * The rule that matters is one notification per event, never one per item: a
 * first scan over a large folder imports hundreds, and an app that sends
 * hundreds of notifications is an app whose notifications get turned off.
 */
class NotificationsTest extends TestCase
{
    use RefreshDatabase;

    private function signedIn(): array
    {
        $user = User::factory()->create();
        $profile = Profile::create([
            'user_id' => $user->id,
            'name' => 'Owner',
            'is_owner' => true,
            'is_default' => true,
        ]);

        $this->actingAs($user);
        $this->withSession([
            'profile_id' => $profile->id,
            'profile_unlocked_at' => now()->timestamp,
        ]);

        return [$user, $profile];
    }

    public function test_an_identical_event_is_not_recorded_twice(): void
    {
        // A scan runs every few minutes. Without this it would write the same
        // summary forever.
        $first = Notification::record(Notification::SCAN_FINISHED, '3 new items');
        $second = Notification::record(Notification::SCAN_FINISHED, '3 new items');

        $this->assertNotNull($first);
        $this->assertNull($second);
        $this->assertSame(1, Notification::count());
    }

    public function test_the_same_event_is_recorded_again_once_it_is_old(): void
    {
        Notification::record(Notification::SCAN_FINISHED, '3 new items');

        $this->travel(11)->minutes();

        $this->assertNotNull(Notification::record(Notification::SCAN_FINISHED, '3 new items'));
    }

    public function test_the_api_returns_what_happened(): void
    {
        $this->signedIn();

        Notification::record(Notification::SCAN_FINISHED, '2 new items', 'A, B');

        $this->getJson(route('api.notifications'))
            ->assertOk()
            ->assertJsonPath('items.0.type', Notification::SCAN_FINISHED)
            ->assertJsonPath('items.0.title', '2 new items');
    }

    public function test_a_cursor_returns_only_what_is_newer(): void
    {
        $this->signedIn();

        $first = Notification::record(Notification::SCAN_FINISHED, 'first');
        $this->travel(11)->minutes();
        $second = Notification::record(Notification::SCAN_FINISHED, 'second');

        $this->getJson(route('api.notifications', ['since' => $first->id]))
            ->assertOk()
            ->assertJsonCount(1, 'items')
            ->assertJsonPath('items.0.id', $second->id);
    }

    public function test_pruning_drops_old_events(): void
    {
        Notification::record(Notification::SCAN_FINISHED, 'old');
        Notification::query()->update(['created_at' => now()->subDays(45)]);

        // These are "what happened while you were away", not a permanent log.
        Notification::prune(30);

        $this->assertSame(0, Notification::count());
    }

    public function test_an_event_about_a_hidden_item_is_not_offered(): void
    {
        [$user, $profile] = $this->signedIn();

        $item = MediaItem::create([
            'user_id' => $user->id,
            'type' => MediaItemType::Movie,
            'title' => 'Something Rated',
            'processing_status' => \App\Enums\ProcessingStatus::Complete,
            'owned' => true,
        ]);

        $item->metadata()->create(['mpaa_rating' => 'R']);

        Notification::record(Notification::EPISODE_ADDED, 'Something Rated', null, $item);

        // A notification names a title, so an ungated list would tell a capped
        // profile exactly what it is not allowed to see.
        $profile->update(['max_rating' => 'PG']);

        $this->getJson(route('api.notifications'))
            ->assertOk()
            ->assertJsonCount(0, 'items');
    }
}
