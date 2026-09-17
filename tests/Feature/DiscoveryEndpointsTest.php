<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace Tests\Feature;

use App\Models\Profile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The two unauthenticated-by-design endpoints, and the line between them.
 *
 * `/soundchex.json` says "this is a SoundChex server, on this build" — the
 * shell needs it before anyone signs in, and it discloses nothing about the
 * household. `/soundchex-addresses.json` is different in kind: it lists every
 * address the server answers on, and reaching one route does not entitle you
 * to a map of the others. It went out CORS-open to anyone for months.
 */
class DiscoveryEndpointsTest extends TestCase
{
    use RefreshDatabase;

    public function test_identity_stays_public_and_minimal(): void
    {
        $this->getJson('/soundchex.json')
            ->assertOk()
            ->assertJson(['app' => 'soundchex'])
            // The whole payload. Anything more added here should have to
            // argue with this test about whether a stranger may see it.
            ->assertJsonStructure(['app', 'version', 'build']);
    }

    public function test_addresses_refuse_the_signed_out(): void
    {
        $response = $this->get('/soundchex-addresses.json');

        $response->assertRedirect();
        $this->assertStringNotContainsString('addresses', (string) $response->getContent());
    }

    public function test_addresses_answer_the_signed_in(): void
    {
        $user = User::factory()->create();
        Profile::create(['user_id' => $user->id, 'name' => 'Owner', 'is_owner' => true]);

        $this->actingAs($user)
            ->getJson('/soundchex-addresses.json')
            ->assertOk()
            ->assertJsonStructure(['addresses']);
    }

    public function test_addresses_no_longer_offer_cors_to_the_world(): void
    {
        $user = User::factory()->create();
        Profile::create(['user_id' => $user->id, 'name' => 'Owner', 'is_owner' => true]);

        $response = $this->actingAs($user)->getJson('/soundchex-addresses.json');

        $this->assertNotSame('*', $response->headers->get('Access-Control-Allow-Origin'));
    }
}
