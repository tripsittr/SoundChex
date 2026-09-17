<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * What the host application reads to answer "is this working?".
 *
 * Unauthenticated, because the server app has to show something before anyone
 * has signed in — and loopback-only, because it describes the machine rather
 * than the library and that is nobody else's business.
 */
class ServerHealthTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_reports_health_without_signing_in(): void
    {
        $this->getJson(route('api.server.health'))
            ->assertOk()
            ->assertJsonPath('app', 'soundchex')
            ->assertJsonStructure([
                'queue_running',
                'queue_depth',
                'scheduler_running',
                'library' => ['items', 'pending'],
                'addresses',
            ]);
    }

    public function test_it_refuses_a_request_from_the_network(): void
    {
        // Not merely denied: a 404 says nothing about what is here. Checked
        // against the connection's address rather than a header, since a header
        // is set by whoever is asking.
        $this->withServerVariables(['REMOTE_ADDR' => '192.168.1.50'])
            ->getJson(route('api.server.health'))
            ->assertNotFound();
    }

    public function test_the_scheduler_is_only_healthy_with_a_recent_heartbeat(): void
    {
        cache()->forget('soundchex.scheduler.heartbeat');

        $this->getJson(route('api.server.health'))
            ->assertJsonPath('scheduler_running', false);

        cache()->put('soundchex.scheduler.heartbeat', now()->timestamp, now()->addMinutes(10));

        $this->getJson(route('api.server.health'))
            ->assertJsonPath('scheduler_running', true);
    }

    public function test_a_stale_heartbeat_is_not_healthy(): void
    {
        // schedule:work fires every minute, so a heartbeat from an hour ago
        // means it died an hour ago.
        cache()->put('soundchex.scheduler.heartbeat', now()->subHour()->timestamp, now()->addMinutes(10));

        $this->getJson(route('api.server.health'))
            ->assertJsonPath('scheduler_running', false);
    }

    public function test_it_reports_the_tailnet_address(): void
    {
        $response = $this->getJson(route('api.server.health'))->assertOk();

        // Present whether or not Tailscale is installed: a client reading this
        // should not have to guess whether a missing key means "no tailnet" or
        // "an older server".
        $response->assertJsonStructure([
            'tailnet' => ['ip', 'name', 'expires', 'expiring_soon'],
        ]);
    }

    public function test_an_expiring_key_is_flagged_before_it_bites(): void
    {
        $tailnet = $this->getJson(route('api.server.health'))->json('tailnet');

        if (blank($tailnet['expires'] ?? null)) {
            $this->markTestSkipped('No tailnet on this machine.');
        }

        // A node's key expires and the machine drops off the tailnet until
        // someone re-authenticates it. The address survives but the server
        // becomes unreachable, which on a phone looks exactly like the server
        // being down — so the warning has to come first.
        $this->assertSame(
            strtotime((string) $tailnet['expires']) < now()->addWeeks(4)->timestamp,
            $tailnet['expiring_soon'],
        );
    }
}
