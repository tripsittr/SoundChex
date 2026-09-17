<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace Tests\Feature;

use App\Models\DeviceReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Diagnostics arriving from a device.
 *
 * A phone has no console anyone can reach, so a failed navigation is a white
 * flash and nothing else. These arrive on their own rather than being read
 * aloud from a panel.
 *
 * Unauthenticated on purpose: the failures worth reporting include the ones
 * that stop a device signing in, and a report needing a working session cannot
 * describe a broken one. Every field is therefore bounded.
 */
class DeviceReportsTest extends TestCase
{
    use RefreshDatabase;

    private function payload(array $overrides = []): array
    {
        return [
            'device' => 'device-abc',
            'platform' => 'iPhone',
            'build' => 'abc123',
            'origin' => 'https://example.ts.net',
            'events' => [
                ['kind' => 'served-offline-page', 'at' => 2400, 'path' => '/app/music', 'detail' => ['wanted' => '/app/music']],
            ],
            ...$overrides,
        ];
    }

    public function test_a_device_can_report_without_signing_in(): void
    {
        $this->postJson(route('api.device-reports'), $this->payload())
            ->assertCreated();

        $this->assertSame(1, DeviceReport::count());
    }

    public function test_the_events_are_kept(): void
    {
        $this->postJson(route('api.device-reports'), $this->payload());

        $report = DeviceReport::first();

        $this->assertSame('served-offline-page', $report->events[0]['kind']);
        $this->assertSame('/app/music', $report->events[0]['path']);
    }

    public function test_a_report_without_events_is_refused(): void
    {
        $this->postJson(route('api.device-reports'), $this->payload(['events' => []]))
            ->assertUnprocessable();
    }

    public function test_an_unbounded_report_is_refused(): void
    {
        // Writable by anything that can reach the server, so the size of what
        // it will accept has to be decided here rather than by the sender.
        $this->postJson(route('api.device-reports'), $this->payload([
            'events' => array_fill(0, 200, ['kind' => 'x', 'at' => 1, 'path' => '/', 'detail' => []]),
        ]))->assertUnprocessable();
    }

    public function test_an_overlong_field_is_refused(): void
    {
        $this->postJson(route('api.device-reports'), $this->payload([
            'device' => str_repeat('a', 200),
        ]))->assertUnprocessable();
    }

    public function test_old_reports_are_pruned(): void
    {
        $this->postJson(route('api.device-reports'), $this->payload());

        DeviceReport::query()->update(['created_at' => now()->subDays(30)]);

        // A month-old report from a build that no longer exists is noise, and
        // an unbounded table fed by every device grows without limit.
        DeviceReport::prune(14);

        $this->assertSame(0, DeviceReport::count());
    }

    public function test_recent_reports_survive_pruning(): void
    {
        $this->postJson(route('api.device-reports'), $this->payload());

        DeviceReport::prune(14);

        $this->assertSame(1, DeviceReport::count());
    }
}
