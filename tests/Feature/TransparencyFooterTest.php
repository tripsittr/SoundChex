<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace Tests\Feature;

use App\Models\Profile;
use App\Models\User;
use App\Services\CurrentProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The served app carries a transparency footer: what SoundChex is, and links to
 * its source, licence and policies — reachable from inside the app.
 */
class TransparencyFooterTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsOwner(): self
    {
        $user = User::factory()->create();
        $owner = Profile::create(['user_id' => $user->id, 'name' => 'Owner', 'is_owner' => true]);
        $this->actingAs($user);
        app(CurrentProfile::class)->switchTo($owner->id);

        return $this;
    }

    public function test_the_footer_links_source_licence_and_policies(): void
    {
        $this->actingAsOwner()
            ->get(route('media.home'))
            ->assertOk()
            ->assertSee('Self-hosted media library')
            ->assertSee('github.com/tripsittr/SoundChex')
            ->assertSee('Source (AGPLv3)')
            ->assertSee('Privacy')
            ->assertSee('Terms')
            ->assertSee('Open-source credits');
    }

    public function test_legal_links_follow_a_configured_website(): void
    {
        config(['app.links.website' => 'https://soundchex.example']);

        $this->actingAsOwner()
            ->get(route('media.home'))
            ->assertOk()
            ->assertSee('https://soundchex.example/legal/server-privacy');
    }
}
