<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace Tests\Feature;

use App\Enums\MediaItemType;
use App\Enums\PipelineStage;
use App\Enums\PipelineState;
use App\Enums\ProcessingStatus;
use App\Enums\ReviewReason;
use App\Enums\SystemReviewReason;
use App\Models\MediaItem;
use App\Models\ReviewItem;
use App\Models\User;
use App\Services\Review\ReviewLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * One record for everything that needs a person (#469).
 *
 * Review was a **status, not a record**: five columns plus a reports table,
 * each added for one feature. A new failure kind had nowhere to go, so it went
 * nowhere — which is why the audit found seven ways to end up hidden from the
 * library *and* absent from review at the same time.
 *
 * The invariant these defend is the one the health check asserts: **an item is
 * never hidden without an open review item saying why.** Measured at 96 before
 * the backfill, 0 after.
 */
class ReviewItemsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        Queue::fake();
    }

    /* --------------------------------------------------------- opening -- */

    public function test_opening_records_the_reason_and_its_evidence(): void
    {
        $item = $this->hidden();

        $review = app(ReviewLog::class)->open(
            $item,
            SystemReviewReason::MissingAlbum,
            ['confidence' => 'fuzzy'],
        );

        $this->assertSame(ReviewItem::SOURCE_SYSTEM, $review->source);
        $this->assertSame(SystemReviewReason::MissingAlbum, $review->reasonEnum());
        $this->assertSame('fuzzy', $review->details['confidence']);
        $this->assertTrue($review->isOpen());
    }

    public function test_opening_the_same_reason_twice_updates_rather_than_stacks(): void
    {
        // Re-running a stage must not pile up copies of one complaint. That is
        // what made the old duplicate flag re-fire on every sweep.
        $item = $this->hidden();
        $log = app(ReviewLog::class);

        $log->open($item, SystemReviewReason::MissingAlbum, ['attempt' => 1]);
        $log->open($item, SystemReviewReason::MissingAlbum, ['attempt' => 2]);

        $open = ReviewItem::where('media_item_id', $item->id)->open()->get();

        $this->assertCount(1, $open);
        $this->assertSame(2, $open->first()->details['attempt'], 'The evidence should be refreshed.');
    }

    public function test_two_different_reasons_coexist(): void
    {
        // A file can be several kinds of wrong at once, and each is separately
        // resolvable -- which a single status column could never express.
        $item = $this->hidden();
        $log = app(ReviewLog::class);

        $log->open($item, SystemReviewReason::MissingAlbum);
        $log->open($item, SystemReviewReason::CoverUncertain);

        $this->assertCount(2, $log->openFor($item));
    }

    public function test_a_user_report_is_distinguishable_from_a_system_finding(): void
    {
        // A person saying "the cover is wrong" is evidence of a different kind
        // from a checker measuring a bitrate, and the screen should not present
        // them identically.
        $item = $this->hidden();

        $review = app(ReviewLog::class)->report(
            $item,
            ReviewReason::Cover,
            $this->user,
            note: 'This is the wrong album art',
        );

        $this->assertSame(ReviewItem::SOURCE_USER, $review->source);
        $this->assertInstanceOf(ReviewReason::class, $review->reasonEnum());
        $this->assertSame($this->user->id, $review->user_id);
    }

    /* ------------------------------------------------------- resolving -- */

    public function test_resolving_the_last_item_resumes_the_pipeline(): void
    {
        // Closing the row alone would leave the item parked forever: it is
        // pipeline_state = waiting that keeps it out of the library, and only a
        // resume clears that.
        $item = $this->hidden();
        $log = app(ReviewLog::class);

        $review = $log->open($item, SystemReviewReason::MissingAlbum);

        $log->resolve($review, $this->user, ['chose' => 'single']);

        $fresh = $item->fresh();

        $this->assertSame(PipelineStage::Enriched, $fresh->pipeline_stage, 'Missing album resumes at enrich.');
        $this->assertSame(PipelineState::Queued, $fresh->pipeline_state);
    }

    public function test_resolving_one_of_two_does_not_resume(): void
    {
        // An item with a second open complaint is not ready to move on, and
        // resuming would file it while somebody was mid-decision.
        $item = $this->hidden();
        $log = app(ReviewLog::class);

        $first = $log->open($item, SystemReviewReason::MissingAlbum);
        $log->open($item, SystemReviewReason::CoverUncertain);

        $log->resolve($first, $this->user);

        $this->assertSame(
            PipelineState::Waiting,
            $item->fresh()->pipeline_state,
            'Still waiting, because the cover question is open.',
        );
    }

    public function test_dismissing_stamps_reviewed_at_so_nothing_drags_it_back(): void
    {
        // A re-enrichment that re-flagged a dismissed item is exactly the trap
        // S-302 fixed, and reviewed_at is what every other path checks.
        $item = $this->hidden();
        $log = app(ReviewLog::class);

        $review = $log->open($item, SystemReviewReason::CoverUncertain);

        $log->dismiss($review, $this->user, 'The cover was right');

        $this->assertNotNull($item->fresh()->reviewed_at);
        $this->assertSame(ReviewItem::STATUS_DISMISSED, $review->fresh()->status);
    }

    public function test_dismissing_is_recorded_differently_from_resolving(): void
    {
        // The distinction is the user's answer: a dismissed cover warning means
        // the cover was right all along, a resolved one means they picked a
        // different image. Recording both as "resolved" would lose which.
        $item = $this->hidden();
        $log = app(ReviewLog::class);

        $dismissed = $log->open($item, SystemReviewReason::CoverUncertain);
        $log->dismiss($dismissed);

        $this->assertSame(ReviewItem::STATUS_DISMISSED, $dismissed->fresh()->status);
        $this->assertTrue($dismissed->fresh()->resolution['dismissed']);
    }

    /* ------------------------------------------------------ invariants -- */

    public function test_a_hidden_item_with_nothing_open_is_counted(): void
    {
        // The number that must be zero. An item absent from the library AND
        // absent from review is invisible to every other report.
        $this->hidden();

        $this->assertSame(1, app(ReviewLog::class)->hiddenWithNothingOpen());
    }

    public function test_opening_an_item_clears_it_from_that_count(): void
    {
        $item = $this->hidden();

        app(ReviewLog::class)->open($item, SystemReviewReason::NoMatch);

        $this->assertSame(0, app(ReviewLog::class)->hiddenWithNothingOpen());
    }

    public function test_a_complete_item_is_never_counted(): void
    {
        // The check must not cry wolf over the thousands of settled items in a
        // real library.
        MediaItem::create([
            'user_id' => $this->user->id,
            'type' => MediaItemType::Music,
            'title' => 'Settled',
            'processing_status' => ProcessingStatus::Complete,
            'owned' => true,
        ]);

        $this->assertSame(0, app(ReviewLog::class)->hiddenWithNothingOpen());
    }

    /* ---------------------------------------------------------- reason -- */

    /** @return array<string, array{0: SystemReviewReason, 1: string, 2: PipelineStage}> */
    public static function reasons(): array
    {
        return [
            'no match identifies' => [SystemReviewReason::NoMatch, 'identify', PipelineStage::Identified],
            'missing album enriches' => [SystemReviewReason::MissingAlbum, 'identify', PipelineStage::Enriched],
            'duplicate dedupes' => [SystemReviewReason::Duplicate, 'duplicates', PipelineStage::Deduped],
            'cover enriches' => [SystemReviewReason::CoverUncertain, 'covers', PipelineStage::Enriched],
            'quality rechecks' => [SystemReviewReason::Quality, 'files', PipelineStage::Checked],
            'move failure replans' => [SystemReviewReason::MoveFailed, 'files', PipelineStage::Planned],
            'missing file restarts' => [SystemReviewReason::MissingFile, 'files', PipelineStage::Catalogued],
        ];
    }

    #[DataProvider('reasons')]
    public function test_each_reason_knows_its_job_and_where_to_resume(
        SystemReviewReason $reason,
        string $job,
        PipelineStage $resumeAt,
    ): void {
        // Resuming at the right stage is the difference between a second of
        // work and re-running the whole pipeline.
        $this->assertSame($job, $reason->job());
        $this->assertSame($resumeAt, $reason->resumeAt());
    }

    public function test_only_an_unplayable_file_hides_the_item(): void
    {
        // Most reasons do not hide: an item with a loose match or no album is
        // perfectly usable and should be visible while somebody gets round to
        // it. Hiding everything is what made 8,440 rows invisible.
        $this->assertFalse(SystemReviewReason::LowConfidence->hidesItem());
        $this->assertFalse(SystemReviewReason::MissingAlbum->hidesItem());
        $this->assertFalse(SystemReviewReason::Duplicate->hidesItem());

        $this->assertTrue(SystemReviewReason::Unreadable->hidesItem());
        $this->assertTrue(SystemReviewReason::MissingFile->hidesItem());
    }

    public function test_every_system_reason_maps_to_a_real_job(): void
    {
        // A reason whose job does not exist would leave an item in the
        // database and off every screen.
        $jobs = array_keys(\App\Services\Review\ReviewQueue::JOBS);

        foreach (SystemReviewReason::cases() as $reason) {
            $this->assertContains($reason->job(), $jobs, "{$reason->value} maps to an unknown job.");
        }
    }

    /* -------------------------------------------------------- helpers --- */

    /** An item hidden from the library with nothing explaining why. */
    private function hidden(): MediaItem
    {
        $item = MediaItem::create([
            'user_id' => $this->user->id,
            'type' => MediaItemType::Music,
            'title' => 'Needs a look',
            'processing_status' => ProcessingStatus::NeedsReview,
            'owned' => true,
        ]);

        $item->musicMetadata()->create(['artist' => 'Someone']);

        $item->forceFill([
            'pipeline_stage' => PipelineStage::Identified,
            'pipeline_state' => PipelineState::Waiting,
        ])->saveQuietly();

        return $item->fresh();
    }
}
