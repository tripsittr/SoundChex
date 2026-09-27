<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace Tests\Feature;

use App\Models\User;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Who may create an account, and when.
 *
 * The gate claimed to default to closed and did the opposite: `isOpen()` read
 * `?? false`, but `Settings::getStoredSettings()` fills every key from its own
 * defaults and that one was `true`, so the null coalesce never fired. Every
 * install that had never saved the setting accepted sign-ups — and this server
 * is meant to be tunnelled, so that is the open internet, with the first
 * account created becoming the owner.
 *
 * The middle test here is the one that was failing silently in production.
 */
class RegistrationGateTest extends TestCase
{
    use RefreshDatabase;

    /** The first run has to work: there is no admin panel to be added from yet. */
    public function test_the_first_account_can_be_created_with_no_setting_saved(): void
    {
        $this->assertSame(0, User::count(), 'this test is about an empty install');

        $this->get('/register')->assertOk();

        $this->post('/register', [
            'name' => 'First Owner',
            'email' => 'owner@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $this->assertAuthenticated();

        // Not merely created — created as the owner, which is what makes the
        // open door on first run defensible rather than careless. The role is a
        // Spatie assignment, not a column on `users`.
        $this->assertTrue(User::firstWhere('email', 'owner@example.com')->hasRole('owner'));
    }

    /**
     * The regression this file exists for.
     *
     * One account exists and nobody has touched the setting, so the door must
     * be shut. Before the fix this returned 200 and anyone reachable could
     * create themselves an account.
     */
    public function test_registration_closes_once_an_account_exists(): void
    {
        User::factory()->create();

        $this->assertNull(
            app(SettingsService::class)->get('app_allow_registration'),
            'the point is that nothing has been saved',
        );

        // 404 rather than 403: a closed door should not advertise that there
        // is a door.
        $this->get('/register')->assertNotFound();

        $this->post('/register', [
            'name' => 'Uninvited',
            'email' => 'uninvited@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ])->assertNotFound();

        $this->assertGuest();
        $this->assertNull(User::firstWhere('email', 'uninvited@example.com'));
    }

    /** A household that wants open sign-up can still have it. */
    public function test_registration_reopens_when_the_setting_says_so(): void
    {
        User::factory()->create();

        app(SettingsService::class)->set('app_allow_registration', true);

        $this->get('/register')->assertOk();

        $this->post('/register', [
            'name' => 'Invited',
            'email' => 'invited@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $this->assertAuthenticated();

        // Second account in, so a member rather than the owner.
        $invited = User::firstWhere('email', 'invited@example.com');
        $this->assertTrue($invited->hasRole('member'));
        $this->assertFalse($invited->hasRole('owner'));
    }

    /** A stored `false` is a real choice and outranks nothing else. */
    public function test_an_explicit_false_keeps_it_shut(): void
    {
        User::factory()->create();

        app(SettingsService::class)->set('app_allow_registration', false);

        $this->get('/register')->assertNotFound();
    }
}
