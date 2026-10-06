<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace Tests\Feature;

use App\Enums\IngestOrigin;
use App\Enums\MediaItemType;
use App\Enums\PipelineStage;
use App\Enums\PipelineState;
use App\Enums\ProcessingStatus;
use App\Jobs\Pipeline\RunPipelineStageJob;
use App\Jobs\Pipeline\StageResult;
use App\Models\MediaItem;
use App\Models\User;
use App\Services\LibraryIngest;
use App\Services\Pipeline\PipelineRunner;
use App\Services\Pipeline\PipelineSweeper;
use App\Services\Pipeline\Stage;
use App\Services\Pipeline\StageRegistry;
use App\Services\Pipeline\Stages\CatalogueStage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The guarantee Phase 2 exists to deliver (#489):
 *
 * > No item stays invisible and idle. Every file ends either filed, or waiting
 * > for a person with a reason recorded.
 *
 * Before this, seven distinct paths led to a row hidden from the library
 * *and* absent from review, with nothing that would ever look at it again —
 * most simply a crash between cataloguing a row and dispatching its job.
 */
class PipelineFlowTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
    }

    /* ---------------------------------------------------------- ingest -- */

    public function test_accepting_a_file_creates_the_row_and_its_first_stage_together(): void
    {
        // In one transaction. A crash between the two is the bug: ResolvedScope
        // hides anything that is not `complete`, so a stageless row was absent
        // from the library and from every queue at once.
        Queue::fake();

        $item = $this->accept('media/unsorted/track.mp3');

        $this->assertNotNull($item);
        $this->assertSame(PipelineStage::Catalogued, $item->pipeline_stage);
        $this->assertSame(PipelineState::Queued, $item->pipeline_state);
        $this->assertSame(0, $item->pipeline_attempts);

        Queue::assertPushed(RunPipelineStageJob::class);
    }

    public function test_accepting_a_path_twice_returns_null_rather_than_duplicating_it(): void
    {
        // Every scan sees every file again; "already handled" is the answer,
        // not an error.
        Queue::fake();

        $this->accept('media/unsorted/track.mp3');
        $second = $this->accept('media/unsorted/track.mp3');

        $this->assertNull($second);
        $this->assertSame(1, MediaItem::withoutGlobalScopes()->count());
    }

    public function test_the_origin_is_recorded(): void
    {
        // So "uploaded files behave differently" is answerable from the row
        // rather than by reading five import paths.
        Queue::fake();

        $item = $this->accept('media/unsorted/track.mp3', IngestOrigin::Upload);

        $this->assertSame('upload', $item->enrichment_report['ingest_origin'] ?? null);
    }

    /* ----------------------------------------------------- transitions -- */

    public function test_a_stage_claims_before_working_and_only_once(): void
    {
        // Two workers must not run one stage. The claim is a conditional
        // update, so the second one matches nothing.
        Queue::fake();

        $item = $this->accept('media/unsorted/track.mp3');
        $runner = app(PipelineRunner::class);

        $this->assertTrue($runner->claim($item, PipelineStage::Catalogued));
        $this->assertFalse(
            $runner->claim($item->fresh(), PipelineStage::Catalogued),
            'A second worker must not be able to claim the same stage.',
        );
    }

    public function test_advancing_queues_the_next_stage(): void
    {
        Queue::fake();

        $item = $this->accept('media/unsorted/track.mp3');

        app(PipelineRunner::class)->advance($item, PipelineStage::Catalogued);

        $this->assertSame(PipelineStage::Probed, $item->fresh()->pipeline_stage);
        $this->assertSame(PipelineState::Queued, $item->fresh()->pipeline_state);
    }

    public function test_the_final_stage_leaves_the_item_done_and_visible(): void
    {
        Queue::fake();

        $item = $this->accept('media/unsorted/track.mp3');

        app(PipelineRunner::class)->advance($item, PipelineStage::Published);

        $this->assertSame(PipelineState::Done, $item->fresh()->pipeline_state);
        $this->assertNull(PipelineStage::Published->next(), 'Published is the end of the pipeline.');
    }

    public function test_parking_records_a_reason_and_shows_in_review(): void
    {
        // The state that fixes "lands nowhere": a parked item is visibly
        // waiting, with a reason, rather than hidden and forgotten.
        Queue::fake();

        $item = $this->accept('media/unsorted/track.mp3');

        app(PipelineRunner::class)->park($item, PipelineStage::Identified, 'no source could identify this file');

        $item = $item->fresh();

        $this->assertSame(PipelineState::Waiting, $item->pipeline_state);
        $this->assertSame(ProcessingStatus::NeedsReview, $item->processing_status);
        $this->assertStringContainsString('identify', (string) $item->pipeline_error);
    }

    public function test_a_retry_counts_up_and_eventually_parks(): void
    {
        // A provider blipping is worth another go; three failures is a real
        // problem a fourth attempt will not fix.
        Queue::fake();

        $item = $this->accept('media/unsorted/track.mp3');
        $runner = app(PipelineRunner::class);

        $runner->retry($item, PipelineStage::Identified, 'rate limited');
        $this->assertSame(1, $item->fresh()->pipeline_attempts);
        $this->assertSame(PipelineState::Queued, $item->fresh()->pipeline_state);

        $runner->retry($item->fresh(), PipelineStage::Identified, 'rate limited');
        $this->assertSame(2, $item->fresh()->pipeline_attempts);

        $runner->retry($item->fresh(), PipelineStage::Identified, 'rate limited');

        $this->assertSame(
            PipelineState::Waiting,
            $item->fresh()->pipeline_state,
            'Attempts spent means a person has to look, not that it loops.',
        );
    }

    /* -------------------------------------------------------- sweeping -- */

    public function test_the_sweeper_requeues_a_stage_that_stopped_responding(): void
    {
        // A worker killed mid-stage leaves `running` with a stale timestamp.
        // This is what replaces releasing every reservation on boot.
        Queue::fake();

        $item = $this->accept('media/unsorted/track.mp3');

        $item->forceFill([
            'pipeline_stage' => PipelineStage::Identified,
            'pipeline_state' => PipelineState::Running,
            // Past the stage's own timeout.
            'pipeline_updated_at' => now()->subSeconds(PipelineStage::Identified->timeoutSeconds() + 60),
        ])->saveQuietly();

        $outcome = app(PipelineSweeper::class)->sweep();

        $this->assertSame(1, $outcome['stalled']);
        $this->assertSame(PipelineState::Queued, $item->fresh()->pipeline_state);
    }

    public function test_the_sweeper_leaves_a_stage_that_is_still_within_its_timeout(): void
    {
        // Requeueing work that is legitimately running would do it twice.
        Queue::fake();

        $item = $this->accept('media/unsorted/track.mp3');

        $item->forceFill([
            'pipeline_stage' => PipelineStage::Identified,
            'pipeline_state' => PipelineState::Running,
            'pipeline_updated_at' => now()->subSeconds(5),
        ])->saveQuietly();

        $this->assertSame(0, app(PipelineSweeper::class)->sweep()['stalled']);
        $this->assertSame(PipelineState::Running, $item->fresh()->pipeline_state);
    }

    public function test_the_sweeper_never_disturbs_an_item_waiting_for_a_person(): void
    {
        // Re-queueing a parked item would undo the pending decision and spin.
        Queue::fake();

        $item = $this->accept('media/unsorted/track.mp3');

        app(PipelineRunner::class)->park($item, PipelineStage::Identified, 'waiting on a human');

        $item->forceFill(['pipeline_updated_at' => now()->subDays(30)])->saveQuietly();

        app(PipelineSweeper::class)->sweep();

        $this->assertSame(PipelineState::Waiting, $item->fresh()->pipeline_state);
    }

    public function test_the_sweeper_adopts_an_item_that_is_hidden_with_no_stage(): void
    {
        // The exact state this phase abolishes: not `complete`, so invisible,
        // and no stage, so nothing would ever process it. Rows left by the old
        // one-job design and by crashes before this phase.
        Queue::fake();

        $stranded = MediaItem::create([
            'user_id' => $this->user->id,
            'type' => MediaItemType::Music,
            'title' => 'Stranded',
            'file_path' => 'media/unsorted/stranded.mp3',
            'processing_status' => ProcessingStatus::Pending,
            'owned' => true,
        ]);

        $stranded->forceFill(['pipeline_stage' => null, 'pipeline_state' => null])->saveQuietly();

        $this->assertSame(1, app(PipelineSweeper::class)->strandedCount());

        $outcome = app(PipelineSweeper::class)->sweep();

        $this->assertSame(1, $outcome['stranded']);
        $this->assertSame(PipelineStage::Catalogued, $stranded->fresh()->pipeline_stage);
        $this->assertSame(0, app(PipelineSweeper::class)->strandedCount(), 'Nothing may remain hidden and idle.');
    }

    public function test_an_item_that_completed_is_never_counted_as_stranded(): void
    {
        // The health check must not cry wolf over the 8,000 settled items in a
        // real library.
        MediaItem::create([
            'user_id' => $this->user->id,
            'type' => MediaItemType::Music,
            'title' => 'Settled',
            'file_path' => 'media/library/Music/A/One/settled.mp3',
            'processing_status' => ProcessingStatus::Complete,
            'owned' => true,
        ])->forceFill(['pipeline_stage' => null, 'pipeline_state' => null])->saveQuietly();

        $this->assertSame(0, app(PipelineSweeper::class)->strandedCount());
    }

    /* ------------------------------------------------------ the stages -- */

    public function test_the_catalogue_stage_parks_an_item_whose_file_is_missing(): void
    {
        // Rather than letting it travel down a pipeline that would try to
        // hash, probe, tag and move it, failing differently at each step.
        Queue::fake();

        $item = MediaItem::create([
            'user_id' => $this->user->id,
            'type' => MediaItemType::Music,
            'title' => 'Gone',
            'file_path' => 'media/unsorted/not-there.mp3',
            'owned' => true,
        ]);

        $outcome = app(CatalogueStage::class)->run($item);

        // Parked with a reason, whichever way the path failed: a relative path
        // with no file behind it does not resolve at all, which is a different
        // sentence from a resolvable path whose file is gone. Both are review
        // items rather than silent failures, which is the point.
        $this->assertSame(StageResult::NeedsReview, $outcome->result);
        $this->assertNotEmpty($outcome->reason, 'A parked item must say why.');
    }

    public function test_the_catalogue_stage_passes_a_file_that_is_there(): void
    {
        // The other half: a readable file must not be parked, or nothing would
        // ever get through the first stage.
        Queue::fake();

        $item = $this->accept('media/unsorted/present.mp3');

        $outcome = app(CatalogueStage::class)->run($item);

        $this->assertSame(StageResult::Done, $outcome->result);
    }

    public function test_the_catalogue_stage_backfills_a_size_the_ingest_could_not_read(): void
    {
        Queue::fake();

        $item = $this->accept('media/unsorted/sized.mp3');
        $item->forceFill(['file_size' => null])->saveQuietly();

        app(CatalogueStage::class)->run($item->fresh());

        $this->assertSame(strlen('audio bytes'), $item->fresh()->file_size);
    }

    public function test_every_stage_has_a_handler(): void
    {
        // A missing handler would mark an item done without the work
        // happening, which is worse than an error.
        $registry = app(StageRegistry::class);

        foreach (PipelineStage::cases() as $stage) {
            $this->assertInstanceOf(
                Stage::class,
                $registry->for($stage),
                "No handler for {$stage->value}.",
            );
        }
    }

    public function test_the_stages_form_one_unbroken_chain(): void
    {
        // next() is derived from declaration order, so a stage inserted in the
        // wrong place would silently skip work.
        $stage = PipelineStage::Catalogued;
        $seen = 0;

        while ($stage !== null) {
            $seen++;
            $stage = $stage->next();
        }

        $this->assertSame(
            count(PipelineStage::cases()),
            $seen,
            'Walking next() from the first stage must reach every stage exactly once.',
        );
    }

    private function accept(string $relative, IngestOrigin $origin = IngestOrigin::Scan): ?MediaItem
    {
        Storage::disk('local')->put($relative, 'audio bytes');

        return app(LibraryIngest::class)->accept(
            Storage::disk('local')->path($relative),
            MediaItemType::Music,
            'Track',
            $origin,
            $this->user->id,
        );
    }
}
