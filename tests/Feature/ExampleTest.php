<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace Tests\Feature;

use Tests\TestCase;

class ExampleTest extends TestCase
{
    /**
     * Root is a front door, not a page.
     *
     * It used to render Laravel's welcome screen, which on a publicly
     * reachable URL advertised the framework and told a visitor nothing.
     */
    public function test_root_sends_a_guest_to_the_login_screen(): void
    {
        $this->get('/')->assertRedirect('/login');
    }
}
