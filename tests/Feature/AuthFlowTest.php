<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace Tests\Feature;

use App\Models\User;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_page_renders(): void
    {
        // An account has to exist for a login form to mean anything. Without
        // one the server sends you to registration instead, which is the whole
        // point of that redirect — see FirstRunEntryTest.
        User::factory()->create();

        $this->get('/login')->assertOk();
    }

    public function test_user_can_register_and_lands_in_the_media_center(): void
    {
        // Somebody joining a library that already exists. The *first* account
        // lands on profiles instead, because a brand-new library has nothing to
        // show yet — FirstRunEntryTest covers that.
        //
        // Registration is closed by default once any account exists (S-446), so
        // this has to be turned on deliberately — which is what an owner
        // inviting someone would do.
        User::factory()->create();
        app(SettingsService::class)->set('app_allow_registration', true);

        $response = $this->post('/register', [
            'name' => 'New User',
            'email' => 'new@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $this->assertAuthenticated();

        // The media center, not an admin dashboard: signing in should put
        // someone in front of their library.
        $response->assertRedirect(route('media.home'));
    }

    public function test_user_can_login_and_logout(): void
    {
        $user = User::factory()->create([
            'password' => 'password123',
        ]);

        $loginResponse = $this->post('/login', [
            'email' => $user->email,
            'password' => 'password123',
        ]);

        $this->assertAuthenticatedAs($user);
        $loginResponse->assertRedirect(route('media.home'));

        $logoutResponse = $this->post('/logout');

        $this->assertGuest();
        $logoutResponse->assertRedirect('/');
    }
}
