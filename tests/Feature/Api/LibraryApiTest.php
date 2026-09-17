<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace Tests\Feature\Api;

use App\Enums\MediaItemType;
use App\Models\MediaItem;
use App\Models\Profile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The sync API.
 *
 * This is a bulk export, which changes what a mistake costs. A missing filter
 * on a web page leaks one screen; here it leaks the entire library to a device
 * that then keeps a copy. So the rating cap is tested harder than anything
 * else, including the case where the cap tightens after a device has already
 * synced.
 */
class LibraryApiTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Profile $owner;

    private Profile $kid;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create(['email' => 'household@example.test']);

        $this->owner = Profile::create([
            'user_id' => $this->user->id,
            'name' => 'Owner',
            'is_owner' => true,
        ]);

        $this->kid = Profile::create([
            'user_id' => $this->user->id,
            'name' => 'Kid',
            'is_owner' => false,
            'max_rating' => 'PG',
        ]);
    }

    /* ----------------------------------------------------------- tokens -- */

    public function test_a_token_is_issued_for_valid_credentials(): void
    {
        $response = $this->postJson(route('api.tokens.store'), [
            'email' => 'household@example.test',
            'password' => 'password',
            'profile_id' => $this->owner->id,
            'device_name' => 'iPhone',
        ])->assertCreated();

        $this->assertNotEmpty($response->json('token'));
        $this->assertSame($this->owner->id, $response->json('profile.id'));
    }

    public function test_bad_credentials_are_refused(): void
    {
        $this->postJson(route('api.tokens.store'), [
            'email' => 'household@example.test',
            'password' => 'wrong',
            'profile_id' => $this->owner->id,
            'device_name' => 'iPhone',
        ])->assertStatus(422);
    }

    public function test_a_profile_on_another_account_is_refused(): void
    {
        $stranger = User::factory()->create();
        $theirs = Profile::create([
            'user_id' => $stranger->id,
            'name' => 'Theirs',
            'is_owner' => true,
        ]);

        $this->postJson(route('api.tokens.store'), [
            'email' => 'household@example.test',
            'password' => 'password',
            'profile_id' => $theirs->id,
            'device_name' => 'iPhone',
        ])->assertStatus(422);
    }

    public function test_a_pin_protected_profile_needs_its_pin(): void
    {
        // A token outlives a session, so skipping the PIN here would make the
        // API the easy way around it rather than a parallel path to it.
        $this->kid->setPin('4821');

        $payload = [
            'email' => 'household@example.test',
            'password' => 'password',
            'profile_id' => $this->kid->id,
            'device_name' => 'iPhone',
        ];

        $this->postJson(route('api.tokens.store'), $payload)->assertStatus(422);

        $this->postJson(route('api.tokens.store'), $payload + ['pin' => '4821'])
            ->assertCreated();
    }

    public function test_the_library_needs_a_token(): void
    {
        $this->getJson(route('api.library'))->assertUnauthorized();
    }

    /* ---------------------------------------------------------- syncing -- */

    public function test_the_owner_receives_the_whole_library(): void
    {
        $this->movie('Family Film', 'G');
        $this->movie('Grown Up Film', 'R');

        $titles = $this->asProfile($this->owner)
            ->getJson(route('api.library'))
            ->assertOk()
            ->json('items.*.title');

        $this->assertContains('Family Film', $titles);
        $this->assertContains('Grown Up Film', $titles);
    }

    public function test_a_capped_profile_never_receives_blocked_titles(): void
    {
        // The failure this endpoint could produce: not one hidden page, but a
        // complete uncapped copy of the library on a child's device.
        $this->movie('Family Film', 'G');
        $this->movie('Grown Up Film', 'R');

        $titles = $this->asProfile($this->kid)
            ->getJson(route('api.library'))
            ->assertOk()
            ->json('items.*.title');

        $this->assertContains('Family Film', $titles);
        $this->assertNotContains('Grown Up Film', $titles);
    }

    public function test_the_payload_carries_no_server_paths(): void
    {
        // file_path is a location on the server's disk. It has no use on a
        // device and would describe the layout of someone's home directory.
        $this->movie('Family Film', 'G');

        $body = $this->asProfile($this->owner)
            ->getJson(route('api.library'))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('file_path', $body);
        $this->assertStringNotContainsString('/media/library', $body);
    }

    public function test_an_unchanged_library_answers_304(): void
    {
        $this->movie('Family Film', 'G');

        $first = $this->asProfile($this->owner)->getJson(route('api.library'))->assertOk();
        $etag = $first->headers->get('ETag');

        $this->assertNotEmpty($etag);

        $this->asProfile($this->owner)
            ->withHeaders(['If-None-Match' => $etag])
            ->getJson(route('api.library'))
            ->assertStatus(304);
    }

    public function test_two_profiles_do_not_share_a_cache_entry(): void
    {
        // The ETag includes the profile: without that, a capped device could
        // present the owner's ETag, get a 304, and keep showing a library it
        // should never have received.
        $this->movie('Grown Up Film', 'R');

        $ownerEtag = $this->asProfile($this->owner)
            ->getJson(route('api.library'))
            ->headers->get('ETag');

        $this->asProfile($this->kid)
            ->withHeaders(['If-None-Match' => $ownerEtag])
            ->getJson(route('api.library'))
            ->assertOk();
    }

    /* ------------------------------------------------------------ delta -- */

    public function test_the_delta_returns_only_what_changed(): void
    {
        $old = $this->movie('Old Film', 'G');
        $old->forceFill(['updated_at' => now()->subDays(5)])->saveQuietly();

        $since = now()->subDay();

        $this->movie('New Film', 'G');

        $titles = $this->asProfile($this->owner)
            ->getJson(route('api.library.delta', ['since' => $since->toIso8601String()]))
            ->assertOk()
            ->json('items.*.title');

        $this->assertContains('New Film', $titles);
        $this->assertNotContains('Old Film', $titles);
    }

    public function test_the_delta_reports_what_the_device_should_drop(): void
    {
        // A row that is merely absent from an updates list is
        // indistinguishable from one that never matched — the device cannot
        // tell "deleted" from "you never had this".
        $film = $this->movie('Deleted Film', 'G');
        $id = $film->id;

        $film->delete();

        $removed = $this->asProfile($this->owner)
            ->postJson(route('api.library.delta'), [
                'since' => now()->subDay()->toIso8601String(),
                'known_ids' => [$id],
            ])
            ->assertOk()
            ->json('removed_ids');

        $this->assertContains($id, $removed);
    }

    public function test_a_tightened_cap_removes_what_was_already_synced(): void
    {
        // The case a delete-only mechanism misses entirely: the item still
        // exists, the profile simply may no longer have it. Without this the
        // device keeps playing a film it is now barred from.
        $film = $this->movie('Grown Up Film', 'R');

        $removed = $this->asProfile($this->kid)
            ->postJson(route('api.library.delta'), [
                'since' => now()->subYear()->toIso8601String(),
                'known_ids' => [$film->id],
            ])
            ->assertOk()
            ->json('removed_ids');

        $this->assertContains($film->id, $removed);
    }

    /* -------------------------------------------------------- helpers --- */

    /**
     * Authenticates as a profile the way a real token does.
     *
     * The ability is what CurrentProfile reads back, so a test that skipped it
     * would exercise a code path no device ever takes.
     */
    private function asProfile(Profile $profile): self
    {
        Sanctum::actingAs($this->user, ['profile:' . $profile->id]);

        return $this;
    }

    private function movie(string $title, ?string $rating): MediaItem
    {
        $item = MediaItem::create([
            'user_id' => $this->user->id,
            'type' => MediaItemType::Movie,
            'title' => $title,
            'file_path' => '/media/library/Movies/' . $title . '.mkv',
            'owned' => true,
        ]);

        $item->movieMetadata()->create([
            'release_year' => 2026,
            'mpaa_rating' => $rating,
        ]);

        return $item->fresh();
    }
}
