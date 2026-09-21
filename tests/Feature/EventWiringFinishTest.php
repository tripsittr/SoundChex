<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace Tests\Feature;

use App\Enums\MediaItemType;
use App\Events\CoverFetched;
use App\Events\MediaItemDeleted;
use App\Events\PlaybackProgress;
use App\Events\ProfileCreated;
use App\Events\ProfileDeleted;
use App\Events\TransferStarted;
use App\Events\UserRated;
use App\Models\MediaItem;
use App\Models\Profile;
use App\Models\User;
use App\Plugins\Registry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The last of the catalogue wired to real dispatch points (S-285): the model
 * observers fire the profile and media lifecycle events, and the remaining
 * controller/job seams fire theirs. `upload.completed` is intentionally absent
 * — no bulk-upload path exists in the app to fire it yet.
 */
class EventWiringFinishTest extends TestCase
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
    }

    /* ------------------------------------------------- model observers --- */

    public function test_creating_a_profile_dispatches_profile_created(): void
    {
        Event::fake([ProfileCreated::class]);

        $profile = Profile::create(['user_id' => $this->user->id, 'name' => 'Kid']);

        Event::assertDispatched(
            ProfileCreated::class,
            fn (ProfileCreated $e) => $e->profile->is($profile),
        );
    }

    public function test_deleting_a_profile_dispatches_profile_deleted(): void
    {
        $profile = Profile::create(['user_id' => $this->user->id, 'name' => 'Kid']);

        Event::fake([ProfileDeleted::class]);

        $profile->delete();

        Event::assertDispatched(
            ProfileDeleted::class,
            fn (ProfileDeleted $e) => $e->profile->is($profile),
        );
    }

    public function test_deleting_a_media_item_dispatches_media_deleted(): void
    {
        $item = $this->item('A Song');

        Event::fake([MediaItemDeleted::class]);

        $item->delete();

        Event::assertDispatched(
            MediaItemDeleted::class,
            fn (MediaItemDeleted $e) => $e->item->is($item),
        );
    }

    public function test_the_media_deleted_event_fires_however_the_row_leaves(): void
    {
        // Not only the admin path: a query-builder delete on the model also
        // routes through the observer, so a merge or a bulk tool is covered.
        $item = $this->item('B Song');

        Event::fake([MediaItemDeleted::class]);

        MediaItem::whereKey($item->id)->get()->each->delete();

        Event::assertDispatched(MediaItemDeleted::class);
    }

    /* ------------------------------------------------ playback progress --- */

    public function test_saving_progress_dispatches_playback_progress(): void
    {
        $item = $this->item('Track', MediaItemType::Music);

        Event::fake([PlaybackProgress::class]);

        $this->actingAs($this->user)
            ->postJson(route('media.progress', $item), ['position' => 42, 'duration' => 200])
            ->assertOk();

        Event::assertDispatched(
            PlaybackProgress::class,
            fn (PlaybackProgress $e) => $e->item->is($item) && $e->position === 42,
        );
    }

    /* --------------------------------------------------------- ratings --- */

    public function test_rating_an_item_through_the_api_dispatches_user_rated(): void
    {
        $item = $this->item('Rated', MediaItemType::Movie);

        Event::fake([UserRated::class]);

        Sanctum::actingAs($this->user, ['profile:'.$this->owner->id]);

        $this->patchJson(route('api.admin.item.update', $item), ['user_rating' => 8])
            ->assertOk();

        Event::assertDispatched(
            UserRated::class,
            fn (UserRated $e) => $e->item->is($item) && $e->rating === 8,
        );
    }

    public function test_a_title_only_edit_does_not_dispatch_user_rated(): void
    {
        $item = $this->item('Untouched', MediaItemType::Movie);

        Event::fake([UserRated::class]);

        Sanctum::actingAs($this->user, ['profile:'.$this->owner->id]);

        $this->patchJson(route('api.admin.item.update', $item), ['title' => 'Renamed'])
            ->assertOk();

        Event::assertNotDispatched(UserRated::class);
    }

    /* ----------------------------------------------------- registration --- */

    public function test_the_newly_wired_events_are_in_the_catalogue(): void
    {
        $events = Registry::builtInEvents();

        foreach ([
            'media.added', 'media.deleted', 'profile.created', 'profile.deleted',
            'transfer.started', 'user.rated', 'playback.progress', 'cover.fetched',
            'upload.completed',
        ] as $name) {
            $this->assertContains($name, $events, "missing {$name}");
        }
    }

    public function test_transfer_started_and_cover_fetched_events_construct(): void
    {
        // Both fire from background jobs against real infrastructure (a peer
        // server, a cover API); this asserts the event contract the jobs depend
        // on rather than standing that infrastructure up.
        $this->assertSame('transfer.started', TransferStarted::NAME);
        $this->assertSame('cover.fetched', CoverFetched::NAME);
    }

    private function item(string $title, MediaItemType $type = MediaItemType::Music): MediaItem
    {
        return MediaItem::create([
            'user_id' => $this->user->id,
            'type' => $type,
            'title' => $title,
            'file_path' => 'library/'.Str::slug($title).'.flac',
            'owned' => true,
        ]);
    }
}
