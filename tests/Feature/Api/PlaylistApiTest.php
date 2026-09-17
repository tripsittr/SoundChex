<?php

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
