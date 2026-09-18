<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * AGPLv3 §13 requires that anyone who interacts with SoundChex over a network
 * be offered its corresponding source. The login page is the first
 * network-facing surface (before auth), so the offer must be there.
 */
class AgplSourceOfferTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_login_page_offers_the_source_code(): void
    {
        $this->get(route('login'))
            ->assertOk()
            ->assertSee('github.com/tripsittr/SoundChex')
            ->assertSee('AGPL', false);
    }
}
