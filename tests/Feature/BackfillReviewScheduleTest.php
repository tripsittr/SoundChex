<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace Tests\Feature;

use App\Enums\MediaItemType;
use App\Enums\ProcessingStatus;
use App\Models\MediaItem;
use App\Models\User;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The invariant is maintained, not merely asserted (#510).
 *
 * `server:health` checked hourly that no item is hidden without an open review
 * item saying why — and nothing restored it. `library:backfill-review` is the
 * remedy and ran only when somebody typed it.
 *
 * Observed over one session as enrichment produced fuzzy matches: the count
 * went 8, then 94, then 377, then 611, with health reporting "unhealthy" each
 * hour and the fix sitting behind a command the owner had no reason to know
 * existed. A dashboard that reports a problem nobody can clear teaches people
 * to ignore the dashboard.
 */
class BackfillReviewScheduleTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_backfill_is_scheduled(): void
    {
        $commands = collect(app(Schedule::class)->events())
            ->map(fn ($event): string => (string) $event->command);

        $this->assertTrue(
            $commands->contains(fn (string $command): bool => str_contains($command, 'library:backfill-review')),
            'Nothing schedules the backfill, so health reports a problem with no remedy.',
        );
    }

    public function test_it_runs_as_often_as_the_sweep_that_creates_the_work(): void
    {
        // The two fix opposite halves of one guarantee: the sweep parks an
        // item, this explains why. A slower backfill would leave a window
        // where health is right to complain and nothing is wrong.
        $events = collect(app(Schedule::class)->events());

        $backfill = $events->first(fn ($e): bool => str_contains((string) $e->command, 'library:backfill-review'));
        $sweep = $events->first(fn ($e): bool => str_contains((string) $e->command, 'library:pipeline-sweep'));

        $this->assertNotNull($backfill);
        $this->assertNotNull($sweep);
        $this->assertSame($sweep->expression, $backfill->expression);
    }

    public function test_it_says_nothing_when_there_is_nothing_to_do(): void
    {
        // Scheduled every five minutes, so a line per run would be 288 lines a
        // day saying nothing happened.
        // `doesntExpectOutput()` rather than `expectsOutput('')`, which asserts
        // that an empty line *was* printed -- the opposite of silence.
        $this->artisan('library:backfill-review --quiet-ok')
            ->doesntExpectOutputToContain('Nothing to do')
            ->doesntExpectOutputToContain('now says why')
            ->assertSuccessful();
    }

    public function test_it_still_speaks_when_it_finds_something(): void
    {
        // Silence on a clean run must not become silence on a dirty one: the
        // interesting case is the one that found work.
        $user = User::factory()->create();

        MediaItem::create([
            'user_id' => $user->id,
            'type' => MediaItemType::Music,
            'title' => 'Unexplained',
            'processing_status' => ProcessingStatus::NeedsReview,
            'owned' => true,
        ])->musicMetadata()->create(['artist' => 'Someone']);

        $this->artisan('library:backfill-review --quiet-ok')
            ->expectsOutputToContain('now says why')
            ->assertSuccessful();
    }
}
