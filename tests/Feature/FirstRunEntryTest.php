<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace Tests\Feature;

use App\Models\User;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Where a server with no account sends you.
 *
 * A fresh install landed on a login form. There were no credentials to type and
 * nothing on the page said why, so the only way forward was knowing to type
 * `/register` by hand. Until an account exists, every entrance is registration.
 */
class FirstRunEntryTest extends TestCase
{
    use RefreshDatabase;

    public function test_root_sends_you_to_register_when_no_account_exists(): void
    {
        $this->get('/')->assertRedirect(route('register'));
    }

    public function test_the_login_page_sends_you_to_register_when_no_account_exists(): void
    {
        $this->get('/login')->assertRedirect(route('register'));
    }

    public function test_root_sends_you_to_login_once_an_account_exists(): void
    {
        User::factory()->create();

        $this->get('/')->assertRedirect(route('login'));
    }

    public function test_the_login_page_is_shown_once_an_account_exists(): void
    {
        User::factory()->create();

        $this->get('/login')->assertOk();
    }

    /**
     * The flag the desktop app reads to decide where to open. Loopback only:
     * this endpoint is public and CORS-open, and an unclaimed library is exactly
     * what a network scan would like to find.
     */
    public function test_identity_tells_a_local_caller_that_setup_is_required(): void
    {
        $this->get('/soundchex.json')
            ->assertOk()
            ->assertJson(['app' => 'soundchex', 'setup_required' => true]);
    }

    public function test_identity_stops_saying_so_once_an_account_exists(): void
    {
        User::factory()->create();

        $this->get('/soundchex.json')
            ->assertOk()
            ->assertJson(['setup_required' => false]);
    }

    public function test_identity_does_not_tell_a_remote_caller_anything_about_setup(): void
    {
        $this->withServerVariables(['REMOTE_ADDR' => '192.168.1.50'])
            ->get('/soundchex.json')
            ->assertOk()
            ->assertJsonMissingPath('setup_required');
    }

    /**
     * The owner of a brand-new library has no media and no profile, so the
     * library itself is an empty room. Profiles is the one screen with
     * something to do.
     */
    public function test_the_first_account_lands_on_profiles_after_registering(): void
    {
        $this->post('/register', [
            'name' => 'Owner',
            'email' => 'owner@example.test',
            'password' => 'a-long-enough-password',
            'password_confirmation' => 'a-long-enough-password',
        ])->assertRedirect(route('profiles.index'));

        $this->assertTrue(User::firstWhere('email', 'owner@example.test')->hasRole('owner'));
    }

    public function test_a_later_account_still_lands_on_the_library(): void
    {
        User::factory()->create();

        // Closed by default once anyone exists (S-446); an owner inviting a
        // second person opens it.
        app(SettingsService::class)->set('app_allow_registration', true);

        $this->post('/register', [
            'name' => 'Member',
            'email' => 'member@example.test',
            'password' => 'a-long-enough-password',
            'password_confirmation' => 'a-long-enough-password',
        ])->assertRedirect(route('media.home'));

        $this->assertTrue(User::firstWhere('email', 'member@example.test')->hasRole('member'));
    }
}
