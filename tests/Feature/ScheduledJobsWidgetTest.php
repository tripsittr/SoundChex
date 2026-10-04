<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace Tests\Feature;

use App\Services\HostServices;
use App\Services\ScheduleInspector;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * The schedule is defined in `routes/console.php` and was invisible from the
 * panel. A task that stopped running — because the scheduler died, or its
 * command began failing — looked exactly like one with nothing to do.
 */
class ScheduledJobsWidgetTest extends TestCase
{
    public function test_it_lists_the_scheduled_tasks(): void
    {
        $tasks = app(ScheduleInspector::class)->all();

        $this->assertNotEmpty($tasks, 'This app schedules work; the table should find it.');

        $task = $tasks->first();

        foreach (['key', 'name', 'expression', 'frequency', 'next_run', 'last_run', 'last_outcome'] as $field) {
            $this->assertArrayHasKey($field, $task);
        }
    }

    public function test_it_describes_the_common_frequencies_in_words(): void
    {
        $expressions = app(ScheduleInspector::class)->all()->pluck('frequency', 'expression');

        $this->assertSame('Every minute', $expressions['* * * * *'] ?? 'Every minute');

        foreach ($expressions as $expression => $frequency) {
            // Never blank: an unrecognised expression falls back to itself,
            // which reads poorly but is never wrong.
            $this->assertNotSame('', $frequency, "No wording for {$expression}");
        }
    }

    public function test_a_task_with_no_recorded_run_reports_nothing_rather_than_guessing(): void
    {
        Cache::flush();

        $task = app(ScheduleInspector::class)->all()->first();

        $this->assertNull($task['last_run']);
        $this->assertNull($task['last_outcome']);
    }

    public function test_a_recorded_run_comes_back(): void
    {
        Cache::flush();

        $inspector = app(ScheduleInspector::class);
        $event = collect(app(Schedule::class)->events())->first();

        $inspector->recordRun($event, 'ok', 1.25);

        $task = $inspector->all()->firstWhere('key', $event->mutexName());

        $this->assertNotNull($task['last_run']);
        $this->assertSame('ok', $task['last_outcome']);
        $this->assertSame(1250, $task['last_duration_ms']);
    }

    /**
     * A failing task must not keep showing its last good run — that is the most
     * misleading thing the table could say.
     */
    public function test_a_failure_replaces_the_previous_outcome(): void
    {
        Cache::flush();

        $inspector = app(ScheduleInspector::class);
        $event = collect(app(Schedule::class)->events())->first();

        $inspector->recordRun($event, 'ok', 0.5);
        $inspector->recordRun($event, 'failed', 0.1);

        $this->assertSame('failed', $inspector->all()->firstWhere('key', $event->mutexName())['last_outcome']);
    }

    /**
     * The Services page said only that it could not manage services here, which
     * left people looking for a service to install that they should not create.
     */
    public function test_the_services_page_explains_what_supervises_this_platform(): void
    {
        $platform = app(HostServices::class)->platformSupervision();

        if (PHP_OS_FAMILY === 'Darwin') {
            $this->assertNull($platform, 'macOS manages launchd on the page itself.');

            return;
        }

        $this->assertIsArray($platform);
        $this->assertNotEmpty($platform['manager']);
        $this->assertNotEmpty($platform['processes']);
        $this->assertNotEmpty($platform['autostart']);

        if (PHP_OS_FAMILY === 'Windows') {
            $this->assertStringContainsString('Server app', $platform['manager'].$platform['summary']);
            $this->assertStringNotContainsString(
                'scheduled task',
                strtolower($platform['summary']),
                'The Server app supervises these; a scheduled task would be a second supervisor.',
            );
        }
    }
}
