<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace Tests\Feature;

use App\Models\Profile;
use App\Models\User;
use App\Services\CurrentProfile;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Signing in to the app must be enough to open the admin panel.
 *
 * There is one guard, so the session was always shared — but `canAccessPanel`
 * asks the *profile*, and the profile created at first use was never flagged as
 * the owner. `add_owner_flag_to_profiles` marks the first profile on each
 * account, and on a fresh install it runs before anyone has registered, so it
 * had nothing to mark.
 *
 * The person who set the server up was therefore refused the panel and sent to
 * Filament's login while already signed in, which reads as a broken session.
 */
class OwnerReachesTheAdminPanelTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_first_profile_on_an_account_is_its_owner(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user);

        $profile = app(CurrentProfile::class)->get();

        $this->assertNotNull($profile, 'An account should get a profile on first use.');
        $this->assertTrue(
            $profile->isOwner(),
            'The first profile on an account is its owner — the admin panel is gated on it.'
        );
    }

    /**
     * Signing in to the app is enough; the panel opens with no second login.
     *
     * The gate answers 403 rather than redirecting, so asserting "not sent to
     * the login page" proved nothing — it passed with the bug in place. The
     * status is what distinguishes the two states.
     */
    public function test_a_signed_in_owner_can_open_the_admin_panel(): void
    {
        $user = User::factory()->create();
        Role::findOrCreate('owner', 'web');
        $user->assignRole('owner');

        $this->actingAs($user);

        app(CurrentProfile::class)->get();

        $this->assertTrue(
            $user->canAccessPanel(Filament::getPanel('admin')),
            'The owner of the library must clear the panel gate.'
        );

        $this->get('/admin')->assertOk();
    }

    public function test_a_second_profile_is_not_an_owner(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user);
        app(CurrentProfile::class)->get();

        $other = Profile::create([
            'user_id' => $user->id,
            'name' => 'Kids',
            'color' => Profile::COLORS[0],
        ]);

        $this->assertFalse(
            $other->isOwner(),
            'Only the first profile is the owner; the rest are household members.'
        );
    }

    /**
     * The repair for installs already in the broken state — an account with
     * profiles, none of them flagged.
     */
    public function test_the_repair_promotes_the_default_profile_when_none_is_owner(): void
    {
        $user = User::factory()->create();

        $stranded = Profile::create([
            'user_id' => $user->id,
            'name' => 'Stranded',
            'color' => Profile::COLORS[0],
            'is_default' => true,
            'is_owner' => false,
        ]);

        $this->assertFalse($stranded->isOwner());

        (include database_path('migrations/2026_10_03_090000_restore_the_owner_profile_on_accounts_with_none.php'))->up();

        $this->assertTrue(
            $stranded->fresh()->isOwner(),
            'An account with no owner profile should have its default promoted.'
        );
    }
}
