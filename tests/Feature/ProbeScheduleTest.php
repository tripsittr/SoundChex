<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace Tests\Feature;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Files catalogued before anything measured them get measured eventually.
 *
 * `ProbeStage` probes everything that goes through the pipeline, so anything
 * added since it existed has a probe row. Nothing ever went back for the rest,
 * and nothing ever would — the command skips already-probed items, so it was
 * safe to run and there was simply no reason for an owner to know it existed.
 *
 * The cost is quiet. Capability badges — 4K, Dolby Vision, 5.1, CC — are
 * derived from the probe, so an unprobed film shows none of them and reads as
 * a broken feature rather than a file nobody has read. The quality checks that
 * find a truncated file are in the same position.
 *
 * Measured on this machine while writing it: **0 of 5** video items had a
 * probe row, two of them real films sitting at `pipeline_state = done`.
 */
class ProbeScheduleTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_probe_is_scheduled(): void
    {
        $commands = collect(app(Schedule::class)->events())
            ->map(fn ($event): string => (string) $event->command);

        $this->assertTrue(
            $commands->contains(fn (string $command): bool => str_contains($command, 'library:probe')),
            'Nothing schedules the probe, so a library catalogued before ProbeStage '
            .'existed never gets capability badges or quality checks.',
        );
    }

    /**
     * Capped per run.
     *
     * Probing reads every file header, and a large library is hours of work.
     * An uncapped hourly command would saturate the disk in one pass; a capped
     * one converges over a night, because already-probed items are skipped.
     */
    public function test_it_takes_a_slice_rather_than_the_whole_library(): void
    {
        $probe = collect(app(Schedule::class)->events())
            ->first(fn ($event): bool => str_contains((string) $event->command, 'library:probe'));

        $this->assertNotNull($probe);
        $this->assertStringContainsString('--limit', (string) $probe->command);
    }

    /**
     * Two of these reading the same files would halve the throughput of both,
     * and a slow batch on a big library will certainly still be running when
     * the next hour comes round.
     */
    public function test_it_does_not_stack_up_behind_itself(): void
    {
        $probe = collect(app(Schedule::class)->events())
            ->first(fn ($event): bool => str_contains((string) $event->command, 'library:probe'));

        $this->assertNotNull($probe);
        $this->assertTrue(
            $probe->withoutOverlapping,
            'An hourly probe over a large library will still be running when the next one starts.',
        );
    }

    /**
     * Skipping already-probed items is what makes a small repeating batch
     * converge instead of re-measuring the same first 200 files for ever.
     */
    public function test_it_skips_what_it_has_already_measured(): void
    {
        $probe = collect(app(Schedule::class)->events())
            ->first(fn ($event): bool => str_contains((string) $event->command, 'library:probe'));

        $this->assertNotNull($probe);
        $this->assertStringNotContainsString(
            '--reprobe',
            (string) $probe->command,
            'A scheduled --reprobe would re-measure the whole library every hour and never converge.',
        );
    }
}
