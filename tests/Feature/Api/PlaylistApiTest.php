<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace Tests\Feature\Api;

use App\Enums\MediaItemType;
use App\Models\Collection;
use App\Models\MediaItem;
use App\Models\Profile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The playlist API the native app and desktop use for Spotify-style editing:
 * rename/description, drag reorder, cover upload, and the enriched payloads
 * (cover URL, track count, total duration). Ownership isolation is tested
 * because playlists belong to an account and a stray id must never leak or
 * mutate another account's playlist.
 */
class PlaylistApiTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Profile $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create(['email' => 'house@example.test']);
        $this->owner = Profile::create([
            'user_id' => $this->user->id,
            'name' => 'Owner',
            'is_owner' => true,
        ]);
    }

    private function asOwner(): self
    {
        Sanctum::actingAs($this->user, ['profile:' . $this->owner->id]);

        return $this;
    }

    private function track(string $title, int $durationMs): MediaItem
    {
        $item = MediaItem::create([
            'user_id' => $this->user->id,
            'type' => MediaItemType::Music,
            'title' => $title,
            'file_path' => '/media/library/Music/' . $title . '.flac',
            'owned' => true,
        ]);

        $item->musicMetadata()->create(['duration_ms' => $durationMs]);

        return $item->fresh();
    }

    public function test_update_renames_and_sets_description(): void
    {
        $playlist = Collection::create(['user_id' => $this->user->id, 'name' => 'Old']);

        $this->asOwner()
            ->patchJson(route('api.playlists.update', $playlist), [
                'name' => 'New name',
                'description' => 'A vibe',
            ])
            ->assertOk()
            ->assertJson(['name' => 'New name', 'description' => 'A vibe']);

        $this->assertDatabaseHas('collections', [
            'id' => $playlist->id,
            'name' => 'New name',
            'description' => 'A vibe',
        ]);
    }

    public function test_show_reports_count_and_total_duration(): void
    {
        $playlist = Collection::create(['user_id' => $this->user->id, 'name' => 'Mix']);
        $a = $this->track('A', 120_000);
        $b = $this->track('B', 180_000);
        $playlist->mediaItems()->attach([$a->id => ['sort_order' => 0], $b->id => ['sort_order' => 1]]);

        $this->asOwner()
            ->getJson(route('api.playlists.show', $playlist))
            ->assertOk()
            ->assertJson([
                'count' => 2,
                'duration_ms' => 300_000,
            ]);
    }

    public function test_reorder_sets_positions_from_the_given_order(): void
    {
        $playlist = Collection::create(['user_id' => $this->user->id, 'name' => 'Mix']);
        $a = $this->track('A', 1000);
        $b = $this->track('B', 1000);
        $c = $this->track('C', 1000);
        $playlist->mediaItems()->attach([
            $a->id => ['sort_order' => 0],
            $b->id => ['sort_order' => 1],
            $c->id => ['sort_order' => 2],
        ]);

        // Reverse the order.
        $this->asOwner()
            ->putJson(route('api.playlists.reorder', $playlist), ['order' => [$c->id, $b->id, $a->id]])
            ->assertOk()
            ->assertJson(['reordered' => true]);

        $ordered = $playlist->fresh()->mediaItems()->pluck('media_items.id')->all();
        $this->assertSame([$c->id, $b->id, $a->id], $ordered);
    }

    public function test_reorder_ignores_ids_not_on_the_playlist(): void
    {
        $playlist = Collection::create(['user_id' => $this->user->id, 'name' => 'Mix']);
        $a = $this->track('A', 1000);
        $stray = $this->track('Stray', 1000);
        $playlist->mediaItems()->attach([$a->id => ['sort_order' => 0]]);

        // A stray id must not be added by a reorder.
        $this->asOwner()
            ->putJson(route('api.playlists.reorder', $playlist), ['order' => [$stray->id, $a->id]])
            ->assertOk();

        $this->assertSame([$a->id], $playlist->fresh()->mediaItems()->pluck('media_items.id')->all());
    }

    public function test_the_list_carries_track_covers_for_the_mosaic(): void
    {
        // The list endpoint sends no tracks, so without these the native app
        // has nothing to draw and every playlist without its own cover shows a
        // note glyph — while the detail screen, which does have the tracks,
        // shows a mosaic. Covers appeared only inside a playlist (S-371).
        $playlist = Collection::create(['user_id' => $this->user->id, 'name' => 'Mine']);

        foreach (['One', 'Two'] as $i => $title) {
            $track = $this->track($title, 1000);
            $track->forceFill(['cover_image_url' => "artwork/{$title}.jpg"])->save();
            $playlist->mediaItems()->attach($track->id, ['sort_order' => $i]);
        }

        $response = $this->asOwner()->getJson(route('api.playlists'));

        $response->assertOk();
        $this->assertCount(2, $response->json('playlists.0.mosaic'));
    }

    public function test_the_mosaic_holds_at_most_four_covers(): void
    {
        // A 2x2 grid needs four; sending 1,191 of them would make the app's
        // first screen pay for the whole library.
        $playlist = Collection::create(['user_id' => $this->user->id, 'name' => 'Mine']);

        foreach (range(1, 6) as $i) {
            $track = $this->track("Track {$i}", 1000);
            $track->forceFill(['cover_image_url' => "artwork/{$i}.jpg"])->save();
            $playlist->mediaItems()->attach($track->id, ['sort_order' => $i]);
        }

        $response = $this->asOwner()->getJson(route('api.playlists'));

        $this->assertCount(4, $response->json('playlists.0.mosaic'));
    }

    public function test_a_playlist_with_no_covers_sends_an_empty_mosaic(): void
    {
        $playlist = Collection::create(['user_id' => $this->user->id, 'name' => 'Mine']);
        $playlist->mediaItems()->attach($this->track('One', 1000)->id, ['sort_order' => 0]);

        $response = $this->asOwner()->getJson(route('api.playlists'));

        $this->assertSame([], $response->json('playlists.0.mosaic'));
    }

    public function test_the_list_does_not_query_once_per_playlist(): void
    {
        // The obvious implementation is a query per card, which makes the
        // app's first screen cost grow with the number of playlists.
        foreach (range(1, 5) as $i) {
            $playlist = Collection::create(['user_id' => $this->user->id, 'name' => "List {$i}"]);
            $track = $this->track("Track {$i}", 1000);
            $track->forceFill(['cover_image_url' => "artwork/{$i}.jpg"])->save();
            $playlist->mediaItems()->attach($track->id, ['sort_order' => 0]);
        }

        \Illuminate\Support\Facades\DB::enableQueryLog();
        $this->asOwner()->getJson(route('api.playlists'))->assertOk();
        $queries = count(\Illuminate\Support\Facades\DB::getQueryLog());
        \Illuminate\Support\Facades\DB::disableQueryLog();

        $this->assertLessThan(10, $queries, "The playlists list ran {$queries} queries; the mosaics are being fetched per playlist.");
    }

    public function test_adding_a_track_that_is_already_there_is_refused(): void
    {
        // The pivot's primary key is (collection_id, media_item_id), so a
        // playlist cannot hold the same track twice. Re-adding one used to
        // rewrite its sort_order and move it to the end, silently (S-373).
        $playlist = Collection::create(['user_id' => $this->user->id, 'name' => 'Mine']);
        $track = $this->track('One', 1000);
        $playlist->mediaItems()->attach($track->id, ['sort_order' => 0]);
        $playlist->mediaItems()->attach($this->track('Two', 1000)->id, ['sort_order' => 1]);

        $response = $this->asOwner()->postJson(
            route('api.playlists.items.add', $playlist),
            ['item_id' => $track->id],
        );

        $response->assertStatus(409)
            ->assertJsonPath('added', false)
            ->assertJsonPath('already_present', true);

        // And it stayed where it was.
        $this->assertSame(
            0,
            (int) $playlist->mediaItems()->whereKey($track->id)->first()->pivot->sort_order,
        );
    }

    public function test_move_to_end_re_adds_a_track_that_is_already_there(): void
    {
        $playlist = Collection::create(['user_id' => $this->user->id, 'name' => 'Mine']);
        $track = $this->track('One', 1000);
        $playlist->mediaItems()->attach($track->id, ['sort_order' => 0]);
        $playlist->mediaItems()->attach($this->track('Two', 1000)->id, ['sort_order' => 1]);

        $this->asOwner()->postJson(
            route('api.playlists.items.add', $playlist),
            ['item_id' => $track->id, 'move_to_end' => true],
        )->assertOk()->assertJsonPath('moved', true);

        $order = $playlist->mediaItems()->orderByPivot('sort_order')->pluck('title')->all();

        $this->assertSame(['Two', 'One'], $order);
        // Moved, not duplicated.
        $this->assertSame(2, $playlist->mediaItems()->count());
    }

    public function test_a_track_that_is_not_there_is_still_added_normally(): void
    {
        $playlist = Collection::create(['user_id' => $this->user->id, 'name' => 'Mine']);

        $this->asOwner()->postJson(
            route('api.playlists.items.add', $playlist),
            ['item_id' => $this->track('One', 1000)->id],
        )->assertOk()
            ->assertJsonPath('added', true)
            ->assertJsonPath('already_present', false);
    }

    public function test_cover_upload_stores_and_exposes_a_url(): void
    {
        Storage::fake('public');
        $playlist = Collection::create(['user_id' => $this->user->id, 'name' => 'Mix']);

        $response = $this->asOwner()
            ->postJson(route('api.playlists.cover', $playlist), [
                'cover' => UploadedFile::fake()->image('cover.jpg', 400, 400),
            ])
            ->assertOk();

        $this->assertNotNull($response->json('artwork_url'));

        $path = $playlist->fresh()->artwork_path;
        $this->assertNotNull($path);
        Storage::disk('public')->assertExists($path);
    }

    public function test_another_account_cannot_update_or_reorder_a_playlist(): void
    {
        $otherUser = User::factory()->create(['email' => 'other@example.test']);
        $playlist = Collection::create(['user_id' => $otherUser->id, 'name' => 'Theirs']);

        // 404 (not 403): a household should not learn another's playlist exists.
        $this->asOwner()
            ->patchJson(route('api.playlists.update', $playlist), ['name' => 'Hijacked'])
            ->assertNotFound();

        $this->asOwner()
            ->putJson(route('api.playlists.reorder', $playlist), ['order' => []])
            ->assertNotFound();

        $this->assertDatabaseHas('collections', ['id' => $playlist->id, 'name' => 'Theirs']);
    }
}
