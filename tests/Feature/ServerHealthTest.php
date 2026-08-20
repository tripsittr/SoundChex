<?php

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
}
