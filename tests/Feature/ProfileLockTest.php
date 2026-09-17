<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace Tests\Feature;

use App\Models\Profile;
use App\Models\User;
use App\Services\CurrentProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * The PIN gate on reopening the app.
 *
 * Sign-in is deliberately long-lived — a media app that asks for a password on
 * the train is one whose downloads may as well not exist — so the session
 * survives the app being closed. That makes the session, on its own, no
 * evidence about who is holding the phone. The PIN is what carries that.
 */
class ProfileLockTest extends TestCase
{
    use RefreshDatabase;

    private function signedIn(array $attributes = []): array
    {
        $user = User::factory()->create();
        $profile = Profile::create([
            'user_id' => $user->id,
            'name' => 'Owner',
            'is_owner' => true,
            'is_default' => true,
            ...$attributes,
        ]);

        $this->actingAs($user);

        return [$user, $profile];
    }

    public function test_a_profile_with_no_pin_is_never_locked(): void
    {
        [, $profile] = $this->signedIn();
        $this->withSession(['profile_id' => $profile->id]);

        // Nothing to prove, so a prompt would be a lock with no key.
        $this->assertTrue(app(CurrentProfile::class)->isUnlocked());
        $this->get(route('media.home'))->assertOk();
    }

    public function test_a_profile_with_a_pin_is_locked_on_a_fresh_session(): void
    {
        [, $profile] = $this->signedIn(['pin_hash' => Hash::make('4103')]);

        // The session carries the profile but no proof the PIN was entered —
        // exactly the state after the app is closed and reopened.
        $this->withSession(['profile_id' => $profile->id]);

        $this->assertFalse(app(CurrentProfile::class)->isUnlocked());
        $this->get(route('media.home'))->assertRedirect(route('profiles.index'));
    }

    public function test_entering_the_pin_unlocks_it(): void
    {
        [, $profile] = $this->signedIn(['pin_hash' => Hash::make('4103')]);
        $this->withSession(['profile_id' => $profile->id]);

        $this->post(route('profiles.switch'), [
            'profile_id' => $profile->id,
            'pin' => '4103',
        ]);

        $this->assertTrue(app(CurrentProfile::class)->isUnlocked());
        $this->get(route('media.home'))->assertOk();
    }

    public function test_the_wrong_pin_leaves_it_locked(): void
    {
        [, $profile] = $this->signedIn(['pin_hash' => Hash::make('4103')]);
        $this->withSession(['profile_id' => $profile->id]);

        $this->post(route('profiles.switch'), [
            'profile_id' => $profile->id,
            'pin' => '0000',
        ]);

        $this->assertFalse(app(CurrentProfile::class)->isUnlocked());
    }

    public function test_an_expired_unlock_locks_again(): void
    {
        [, $profile] = $this->signedIn(['pin_hash' => Hash::make('4103')]);

        // Entered yesterday. A phone picked up the next morning should ask
        // again, or the lock only ever applies once per sign-in.
        $this->withSession([
            'profile_id' => $profile->id,
            'profile_unlocked_at' => now()->subHours(13)->timestamp,
        ]);

        $this->assertFalse(app(CurrentProfile::class)->isUnlocked());
    }

    public function test_a_recent_unlock_still_counts(): void
    {
        [, $profile] = $this->signedIn(['pin_hash' => Hash::make('4103')]);

        // Moving between pages must not re-ask.
        $this->withSession([
            'profile_id' => $profile->id,
            'profile_unlocked_at' => now()->subMinutes(30)->timestamp,
        ]);

        $this->assertTrue(app(CurrentProfile::class)->isUnlocked());
    }

    public function test_the_lock_screen_stays_reachable(): void
    {
        [, $profile] = $this->signedIn(['pin_hash' => Hash::make('4103')]);
        $this->withSession(['profile_id' => $profile->id]);

        // A redirect loop here would leave the app unusable with no way out.
        $this->get(route('profiles.index'))->assertOk();
    }

    public function test_a_locked_profile_gets_a_status_rather_than_html_for_json(): void
    {
        [, $profile] = $this->signedIn(['pin_hash' => Hash::make('4103')]);
        $this->withSession(['profile_id' => $profile->id]);

        // A redirect would be parsed as data and fail confusingly.
        $this->getJson(route('media.home'))->assertStatus(423);
    }

    public function test_a_locked_profile_is_sent_to_the_lock_screen_not_left_stranded(): void
    {
        [, $profile] = $this->signedIn(['pin_hash' => Hash::make('4103')]);
        $this->withSession(['profile_id' => $profile->id]);

        // Settings live under /app, so they are gated too. That is correct —
        // they are behind the lock — but the redirect has to reach a screen
        // that can actually take the PIN, or the profile is unreachable.
        $this->get(route('media.settings'))->assertRedirect(route('profiles.index'));

        $this->post(route('profiles.switch'), [
            'profile_id' => $profile->id,
            'pin' => '4103',
        ]);

        $this->get(route('media.settings'))->assertOk();
    }
}
