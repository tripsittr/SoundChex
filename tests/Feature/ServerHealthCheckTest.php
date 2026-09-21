<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace Tests\Feature;

use App\Events\ServerHealthChecked;
use App\Models\Notification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * The scheduled server health check (S-276): it always emits `server.health`,
 * and records a notification only when something is wrong.
 */
class ServerHealthCheckTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_emits_the_health_event(): void
    {
        Event::fake([ServerHealthChecked::class]);

        $this->artisan('server:health --quiet-ok')->assertSuccessful();

        Event::assertDispatched(ServerHealthChecked::class);
    }

    public function test_a_healthy_server_records_no_notification(): void
    {
        Queue::fake();

        $this->artisan('server:health --quiet-ok')->assertSuccessful();

        // A fresh test DB has no failed jobs and no backlog, so nothing is wrong.
        $this->assertSame(0, Notification::where('type', 'server_health')->count());
    }

    public function test_failed_jobs_make_it_unhealthy_and_notify(): void
    {
        Queue::fake();

        // Put a row in failed_jobs so the check has something to report.
        DB::table('failed_jobs')->insert([
            'uuid' => 'test-'.uniqid(),
            'connection' => 'database',
            'queue' => 'default',
            'payload' => '{}',
            'exception' => 'boom',
            'failed_at' => now(),
        ]);

        $this->artisan('server:health')->assertSuccessful();

        $this->assertSame(1, Notification::where('type', 'server_health')->count());
    }
}
