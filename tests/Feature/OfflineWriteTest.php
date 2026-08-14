<?php

namespace Tests\Feature;

use App\Enums\MediaItemType;
use App\Models\MediaItem;
use App\Models\Profile;
use App\Models\User;
use App\Services\CurrentProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Writes made offline and replayed later.
 *
 * A queued write arrives after the moment it describes, and may arrive twice —
 * a retry after a timeout is indistinguishable from a first attempt. So the
 * endpoints have to be safe to replay, and must not undo something newer.
 */
class OfflineWriteTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Profile $profile;

    private MediaItem $item;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();

        $this->profile = Profile::create([
            'user_id' => $this->user->id,
            'name' => 'Owner',
            'is_owner' => true,
        ]);

        $this->item = MediaItem::create([
            'user_id' => $this->user->id,
            'type' => MediaItemType::Movie,
            'title' => 'Backrooms',
            'file_path' => '/tmp/film.mkv',
            'owned' => true,
        ]);
        $this->item->movieMetadata()->create(['release_year' => 2026]);

        $this->actingAs($this->user);
        app(CurrentProfile::class)->switchTo($this->profile->id);
    }

    /* -------------------------------------------------------- progress -- */

    public function test_progress_is_saved(): void
    {
        $this->postJson(route('media.progress', $this->item), [
            'position' => 120,
            'duration' => 600,
        ])->assertOk();

        $this->assertSame(120, $this->item->plays()->first()->position_seconds);
    }

    public function test_a_stale_replay_does_not_undo_newer_progress(): void
    {
        // The case that matters: a position queued on a train, replayed after
        // the user has already watched further on another device. Applying it
        // would silently rewind them.
        $this->postJson(route('media.progress', $this->item), [
            'position' => 500,
            'duration' => 600,
        ])->assertOk();

        $this->postJson(route('media.progress', $this->item), [
            'position' => 60,
            'duration' => 600,
            'recorded_at' => now()->subHour()->toIso8601String(),
        ])->assertOk()->assertJson(['stale' => true]);

        $this->assertSame(500, $this->item->plays()->first()->position_seconds);
    }

    public function test_a_newer_queued_write_is_applied(): void
    {
        // Proves the guard is a comparison rather than a blanket refusal of
        // anything carrying a timestamp.
        $this->postJson(route('media.progress', $this->item), [
            'position' => 60,
            'duration' => 600,
        ])->assertOk();

        $this->postJson(route('media.progress', $this->item), [
            'position' => 300,
            'duration' => 600,
            'recorded_at' => now()->addMinute()->toIso8601String(),
        ])->assertOk();

        $this->assertSame(300, $this->item->plays()->first()->position_seconds);
    }

    public function test_replaying_the_same_progress_is_harmless(): void
    {
        $payload = ['position' => 120, 'duration' => 600];

        $this->postJson(route('media.progress', $this->item), $payload)->assertOk();
        $this->postJson(route('media.progress', $this->item), $payload)->assertOk();

        // One row per session, not one per replay.
        $this->assertSame(1, $this->item->plays()->count());
        $this->assertSame(120, $this->item->plays()->first()->position_seconds);
    }

    /* ------------------------------------------------------- watchlist -- */

    public function test_the_watchlist_still_toggles_without_a_stated_intent(): void
    {
        // The online button sends no intent and expects a toggle.
        $this->postJson(route('media.watchlist.toggle', $this->item))
            ->assertOk()
            ->assertJson(['inWatchlist' => true]);

        $this->postJson(route('media.watchlist.toggle', $this->item))
            ->assertOk()
            ->assertJson(['inWatchlist' => false]);
    }

    public function test_a_replayed_watchlist_write_does_not_flip_it_back(): void
    {
        // A toggle is not replayable: sent twice it returns to where it
        // started, so an offline write states the result it wants instead.
        $payload = ['in_watchlist' => true];

        $this->postJson(route('media.watchlist.toggle', $this->item), $payload)
            ->assertOk()
            ->assertJson(['inWatchlist' => true]);

        $this->postJson(route('media.watchlist.toggle', $this->item), $payload)
            ->assertOk()
            ->assertJson(['inWatchlist' => true, 'unchanged' => true]);

        $this->assertTrue(
            $this->profile->watchlist()->where('media_item_id', $this->item->id)->exists(),
        );
    }

    public function test_a_stated_removal_replays_safely_too(): void
    {
        $this->profile->watchlist()->attach($this->item->id);

        $payload = ['in_watchlist' => false];

        $this->postJson(route('media.watchlist.toggle', $this->item), $payload)->assertOk();
        $this->postJson(route('media.watchlist.toggle', $this->item), $payload)
            ->assertOk()
            ->assertJson(['inWatchlist' => false]);

        $this->assertFalse(
            $this->profile->watchlist()->where('media_item_id', $this->item->id)->exists(),
        );
    }
}
