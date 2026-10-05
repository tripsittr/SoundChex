<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Root is a front door, not a page.
     *
     * It used to render Laravel's welcome screen, which on a publicly
     * reachable URL advertised the framework and told a visitor nothing.
     *
     * Where it leads depends on whether the server has been claimed: a login
     * form is a dead end until an account exists, so both states are asserted
     * here rather than only the one a seeded database happens to be in.
     */
    public function test_root_sends_a_guest_to_the_login_screen(): void
    {
        User::factory()->create();

        $this->get('/')->assertRedirect('/login');
    }

    public function test_root_sends_a_guest_to_register_before_any_account_exists(): void
    {
        $this->get('/')->assertRedirect(route('register'));
    }
}
