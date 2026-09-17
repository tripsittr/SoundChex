<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace Tests\Feature;

use App\Models\Profile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Where the PIN prompt sits in the profile picker.
 *
 * It was rendered before the profile tile, so asking for a PIN pushed that
 * profile's avatar down the page and the form appeared to belong to whichever
 * tile happened to sit above it.
 *
 * Asserted on the rendered order rather than through a browser: a browser test
 * would have to set a PIN on the shared seeded profile, and leaving one behind
 * locks every test that signs in afterwards.
 */
class ProfilePickerLayoutTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_pin_prompt_renders_after_the_profile_it_unlocks(): void
    {
        $user = User::factory()->create();

        $profile = Profile::create([
            'user_id' => $user->id,
            'name' => 'Owner',
            'is_owner' => true,
            'is_default' => true,
            'pin_hash' => Hash::make('4321'),
        ]);

        $html = $this->actingAs($user)
            ->withSession(['pin_for' => $profile->id])
            ->get(route('profiles.index'))
            ->assertOk()
            ->getContent();

        $tile = strpos($html, 'profile-tile');
        $pin = strpos($html, 'profile-pin"');

        $this->assertNotFalse($tile, 'the tile is rendered');
        $this->assertNotFalse($pin, 'the prompt is rendered');

        $this->assertGreaterThan(
            $tile,
            $pin,
            'the PIN prompt belongs below the profile it unlocks',
        );
    }

    public function test_no_prompt_is_shown_for_a_profile_that_was_not_asked_for(): void
    {
        $user = User::factory()->create();

        Profile::create([
            'user_id' => $user->id,
            'name' => 'Owner',
            'is_owner' => true,
            'is_default' => true,
            'pin_hash' => Hash::make('4321'),
        ]);

        // The picker stays one tap for everyone else.
        $this->actingAs($user)
            ->get(route('profiles.index'))
            ->assertOk()
            ->assertDontSee('profile-pin"', false);
    }
}
