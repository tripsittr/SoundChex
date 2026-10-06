<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace Tests\Feature;

use App\Enums\MediaItemType;
use App\Enums\PipelineStage;
use App\Enums\PipelineState;
use App\Enums\ProcessingStatus;
use App\Enums\SystemReviewReason;
use App\Models\MediaItem;
use App\Models\User;
use App\Services\Review\ReviewLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Re-running the pipeline over a library that already exists (#470).
 *
 * The riskiest operation in the plan, so the tests here are mostly about what
 * it **refuses** to do: run alongside a live worker, move an item somebody is
 * mid-decision on, or do identification and filing in one pass where a bad
 * match would become a misfiled file before anyone could look.
 */
class ReprocessLibraryTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        Queue::fake();
    }

    /* --------------------------------------------------------- refusal -- */

    public function test_it_refuses_without_a_stage_and_prints_the_order(): void
    {
        // A single button over 8,335 items is the thing this must not be. The
        // order is the plan's own, and printing it is cheaper than somebody
        // finding it in a markdown file.
        $this->artisan('library:reprocess')
            ->expectsOutputToContain('Choose a stage')
            ->expectsOutputToContain('db:backup')
            ->expectsOutputToContain('library:manifest')
            ->assertFailed();
    }

    public function test_it_refuses_two_stages_at_once(): void
    {
        // --identify --file in one pass is exactly the combination the staging
        // exists to prevent: a bad identification would become a misfiled file
        // before anybody could look at it.
        $this->artisan('library:reprocess --identify --file')
            ->expectsOutputToContain('Choose a stage')
            ->assertFailed();
    }

    public function test_it_refuses_to_run_while_the_queue_has_work(): void
    {
        // Two processes advancing the same item's stage will disagree about
        // where it is, and Handoff.md is explicit that the bundled runtime
        // holds the database open.
        DB::table('jobs')->insert([
            'queue' => 'default',
            'payload' => '{}',
            'attempts' => 0,
            'available_at' => now()->timestamp,
            'created_at' => now()->timestamp,
        ]);

        $this->artisan('library:reprocess --identify')
            ->expectsOutputToContain('job(s) in the queue')
            ->assertFailed();
    }

    public function test_force_overrides_the_queue_check(): void
    {
        // The check is a heuristic -- a worker on another machine is invisible
        // from here -- so there has to be a way past it, with the risk stated.
        DB::table('jobs')->insert([
            'queue' => 'default',
            'payload' => '{}',
            'attempts' => 0,
            'available_at' => now()->timestamp,
            'created_at' => now()->timestamp,
        ]);

        $this->artisan('library:reprocess --identify --force --limit=1')->assertSuccessful();
    }

    public function test_a_dry_run_is_allowed_even_with_a_live_queue(): void
    {
        // It writes nothing, so it cannot disagree with anybody.
        DB::table('jobs')->insert([
            'queue' => 'default',
            'payload' => '{}',
            'attempts' => 0,
            'available_at' => now()->timestamp,
            'created_at' => now()->timestamp,
        ]);

        $this->artisan('library:reprocess --dry-run')->assertSuccessful();
    }

    /* --------------------------------------------------------- dry run -- */

    public function test_a_dry_run_changes_nothing(): void
    {
        $item = $this->settled();
        $before = $item->only(['pipeline_stage', 'pipeline_state', 'processing_status']);

        $this->artisan('library:reprocess --dry-run')
            ->expectsOutputToContain('Nothing will be written')
            ->assertSuccessful();

        $this->assertSame($before, $item->fresh()->only(['pipeline_stage', 'pipeline_state', 'processing_status']));
        Queue::assertNothingPushed();
    }

    public function test_a_dry_run_counts_items_with_no_identifier(): void
    {
        // A large count means --identify has real work to do before --file is
        // worth running, which is the number the plan wants read first.
        $this->settled();

        $this->artisan('library:reprocess --dry-run')
            ->expectsOutputToContain('With no identifier')
            ->assertSuccessful();
    }

    /* -------------------------------------------------------- identify -- */

    public function test_identify_sends_items_back_to_the_identify_stage(): void
    {
        $item = $this->settled();

        $this->artisan('library:reprocess --identify --force')->assertSuccessful();

        $fresh = $item->fresh();

        $this->assertSame(PipelineStage::Identified, $fresh->pipeline_stage);
        $this->assertSame(PipelineState::Queued, $fresh->pipeline_state);
    }

    public function test_an_item_a_person_has_judged_is_left_alone(): void
    {
        // A bulk re-run must not undo every review ever made. Dragging a
        // reviewed item back is the S-302 trap, and doing it to 8,000 items at
        // once would be the worst possible version of it.
        $item = $this->settled();
        $item->forceFill(['reviewed_at' => now()])->saveQuietly();

        $this->artisan('library:reprocess --identify --force')
            ->expectsOutputToContain('Skipped 1')
            ->assertSuccessful();

        $this->assertSame(
            PipelineStage::Published,
            $item->fresh()->pipeline_stage,
            'A judged item keeps its place.',
        );
    }

    public function test_an_item_with_an_open_question_is_left_alone(): void
    {
        // It is waiting on an answer, and re-running the stage would discard
        // the evidence the reviewer is looking at.
        $item = $this->settled();
        app(ReviewLog::class)->open($item, SystemReviewReason::MissingAlbum);

        $this->artisan('library:reprocess --identify --force')
            ->expectsOutputToContain('Skipped 1')
            ->assertSuccessful();

        $this->assertSame(PipelineStage::Published, $item->fresh()->pipeline_stage);
    }

    /* ------------------------------------------------------------ file -- */

    public function test_filing_skips_an_item_that_is_waiting_for_a_person(): void
    {
        // Moving a file somebody is mid-decision on pre-empts the answer --
        // the same rule the filing gate applies (#460).
        $item = $this->settled();
        $item->forceFill(['pipeline_state' => PipelineState::Waiting])->saveQuietly();

        $this->artisan('library:reprocess --file --force')
            ->expectsOutputToContain('Nothing to reprocess')
            ->assertSuccessful();
    }

    public function test_filing_sends_settled_items_to_the_plan_stage(): void
    {
        // Planned, not Filed: the plan stage computes a target and journals it
        // before anything moves, which is what makes the move reversible.
        $item = $this->settled();

        $this->artisan('library:reprocess --file --force')->assertSuccessful();

        $this->assertSame(PipelineStage::Planned, $item->fresh()->pipeline_stage);
    }

    public function test_filing_reminds_the_operator_to_verify(): void
    {
        // A reprocess nobody checks afterwards is the risk this whole phase is
        // built around.
        $this->settled();

        $this->artisan('library:reprocess --file --force')
            ->expectsOutputToContain('verify-manifest')
            ->assertSuccessful();
    }

    /* --------------------------------------------------------- scoping -- */

    public function test_a_type_filter_narrows_the_run(): void
    {
        $this->settled();
        $this->settled(MediaItemType::Movie);

        $this->artisan('library:reprocess --identify --force --type=movie')
            ->expectsOutputToContain('Sending 1 item')
            ->assertSuccessful();
    }

    public function test_the_limit_is_respected(): void
    {
        // lazyById() discards a limit() on the query, which silently made
        // --limit useless in the manifest command until it was counted by
        // hand instead. Same fix here, same test.
        $this->settled();
        $this->settled();
        $this->settled();

        $this->artisan('library:reprocess --identify --force --limit=2')
            ->expectsOutputToContain('Sending 2 item')
            ->assertSuccessful();
    }

    /* -------------------------------------------------------- helpers --- */

    /** A published item, as the existing library is full of. */
    private function settled(MediaItemType $type = MediaItemType::Music): MediaItem
    {
        $path = 'media/library/x-'.fake()->unique()->numberBetween(1, 999999).'.mp3';
        Storage::disk('local')->put($path, 'audio');

        $item = MediaItem::create([
            'user_id' => $this->user->id,
            'type' => $type,
            'title' => 'Settled',
            'file_path' => Storage::disk('local')->path($path),
            'processing_status' => ProcessingStatus::Complete,
            'owned' => true,
        ]);

        $item->metadata()->create([]);

        $item->forceFill([
            'pipeline_stage' => PipelineStage::Published,
            'pipeline_state' => PipelineState::Done,
        ])->saveQuietly();

        return $item->fresh();
    }
}
