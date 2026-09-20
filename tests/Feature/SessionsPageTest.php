<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace Tests\Feature;

use App\Enums\MediaItemType;
use App\Filament\Pages\Sessions;
use App\Models\MediaItem;
use App\Models\MediaPlay;
use App\Models\Profile;
use App\Models\User;
use App\Services\CurrentProfile;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The Sessions page: who is signed in and what they are listening to (S-36).
 *
 * Device *reports* say what broke on a device; this says who is using the server
 * now. The login side reads Sanctum tokens (one per device, naming a profile),
 * the listening side reads the play rows.
 */
class SessionsPageTest extends TestCase
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

        $this->actingAs($this->user);
        app(CurrentProfile::class)->switchTo($this->owner->id);
        Filament::setCurrentPanel('admin');
    }

    public function test_it_lists_a_signed_in_device_with_its_profile(): void
    {
        $this->user->createToken("Blaze's iPhone", ['profile:'.$this->owner->id]);

        Livewire::test(Sessions::class)
            ->assertSee("Blaze's iPhone")
            ->assertSee('Owner');
    }

    public function test_signing_a_device_out_revokes_its_token(): void
    {
        $token = $this->user->createToken('Old Laptop', ['profile:'.$this->owner->id]);
        $id = $token->accessToken->id;

        Livewire::test(Sessions::class)
            ->call('revoke', $id)
            ->assertHasNoErrors();

        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $id]);
    }

    public function test_it_shows_what_a_profile_is_listening_to(): void
    {
        $item = MediaItem::create([
            'user_id' => $this->user->id,
            'type' => MediaItemType::Music,
            'title' => 'A Memorable Track',
            'owned' => true,
        ]);

        MediaPlay::create([
            'media_item_id' => $item->id,
            'user_id' => $this->user->id,
            'profile_id' => $this->owner->id,
            'position_seconds' => 75,
            'source' => 'album',
        ]);

        Livewire::test(Sessions::class)
            ->assertSee('A Memorable Track')
            ->assertSee('1:15'); // 75s formatted
    }

    public function test_only_the_latest_play_per_profile_is_shown(): void
    {
        $older = $this->play('First Track', minutesAgo: 30);
        $newer = $this->play('Second Track', minutesAgo: 1);

        Livewire::test(Sessions::class)
            ->assertSee('Second Track')
            ->assertDontSee('First Track');
    }

    private function play(string $title, int $minutesAgo): MediaPlay
    {
        $item = MediaItem::create([
            'user_id' => $this->user->id,
            'type' => MediaItemType::Music,
            'title' => $title,
            'owned' => true,
        ]);

        $play = MediaPlay::create([
            'media_item_id' => $item->id,
            'user_id' => $this->user->id,
            'profile_id' => $this->owner->id,
            'position_seconds' => 10,
            'source' => 'album',
        ]);

        $play->forceFill(['updated_at' => now()->subMinutes($minutesAgo)])->saveQuietly();

        return $play;
    }
}
