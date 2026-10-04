<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace Tests\Feature;

use App\Enums\DuplicateStatus;
use App\Enums\MediaItemType;
use App\Jobs\DetectDuplicatesJob;
use App\Models\DuplicateDecision;
use App\Models\MediaItem;
use App\Models\User;
use App\Services\DuplicateDetector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * A reviewed file keeps being searched; the pair that was reviewed does not.
 *
 * These were the same thing until now. A decision lived on the copy —
 * `duplicate_status` said "keeping both" — and the scanner skipped that row for
 * ever, because the row says which item it was compared against but nothing
 * about what was decided. So "we settled A against B" had to be read as "leave
 * A alone", and anything added afterwards was never compared against A at all.
 *
 * `duplicate_decisions` records the pair instead, which separates the two: the
 * question that was answered stays answered, and every other question is still
 * asked. This file is the proof that both halves hold, because getting the
 * second half wrong refills the review list with questions the user already
 * answered — which is worse than the gap being closed.
 */
class ReviewedFilesStayDupeSearchedTest extends TestCase
{
    use RefreshDatabase;

    private DuplicateDetector $detector;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->detector = app(DuplicateDetector::class);
        $this->user = User::factory()->create();
    }

    /**
     * The gap being closed: a kept file meets a copy that arrived later.
     *
     * Before, `check()` returned null for anything already decided, so this
     * third copy could sit alongside a reviewed file indefinitely.
     */
    public function test_a_kept_file_is_searched_again_when_a_new_copy_appears(): void
    {
        $original = $this->item('original.mp3', 'identical bytes');
        $copy = $this->item('copy.mp3', 'identical bytes');

        $this->detector->check($copy);
        $this->detector->keepBoth($copy);

        // A month later, another copy turns up.
        $late = $this->item('late.mp3', 'identical bytes');

        $found = $this->detector->check($copy->fresh());

        $this->assertNotNull($found, 'A reviewed file must still be compared against new arrivals.');
        $this->assertSame($late->id, $found->id);
        $this->assertSame(DuplicateStatus::Pending, $copy->fresh()->duplicate_status);

        // And the earlier ruling is still on record.
        $this->assertTrue(DuplicateDecision::existsFor($copy->id, $original->id));
    }

    /**
     * The half that must not break: the decided pair stays decided.
     *
     * With nothing new to find, a kept file is searched and nothing happens.
     * If this fails, every sweep re-asks every question ever answered.
     */
    public function test_the_pair_already_ruled_on_is_never_raised_again(): void
    {
        $original = $this->item('original.mp3', 'identical bytes');
        $copy = $this->item('copy.mp3', 'identical bytes');

        $this->detector->check($copy);
        $this->detector->keepBoth($copy);

        $this->assertNull($this->detector->check($copy->fresh()));
        $this->assertSame(DuplicateStatus::Kept, $copy->fresh()->duplicate_status);

        // From the other side too: which copy is called the original depends on
        // where each file sits, and that changes when one is filed away.
        $this->assertNull($this->detector->check($original->fresh()));
        $this->assertNull($original->fresh()->duplicate_status);
    }

    /** Keeping both records the ruling, or the next sweep asks again. */
    public function test_keeping_both_records_the_pair(): void
    {
        $original = $this->item('original.mp3', 'identical bytes');
        $copy = $this->item('copy.mp3', 'identical bytes');

        $this->detector->check($copy);

        $this->assertFalse(DuplicateDecision::existsFor($copy->id, $original->id));

        $this->detector->keepBoth($copy);

        $this->assertTrue(DuplicateDecision::existsFor($copy->id, $original->id));
        $this->assertSame(
            DuplicateStatus::Kept,
            DuplicateDecision::first()->decision,
        );
    }

    /** Stored lowest id first, so the pair reads the same from either end. */
    public function test_a_ruling_is_found_from_either_side(): void
    {
        $first = $this->item('one.mp3', 'a');
        $second = $this->item('two.mp3', 'b');

        // Recorded high-then-low, to prove the order it is given does not
        // decide the order it is stored in.
        DuplicateDecision::record($second->id, $first->id, DuplicateStatus::Kept);

        $this->assertTrue(DuplicateDecision::existsFor($first->id, $second->id));
        $this->assertTrue(DuplicateDecision::existsFor($second->id, $first->id));
        $this->assertSame([$second->id], DuplicateDecision::partnersOf($first->id));
        $this->assertSame([$first->id], DuplicateDecision::partnersOf($second->id));
    }

    /** Ruling on a pair twice replaces the ruling rather than failing. */
    public function test_a_pair_can_be_ruled_on_again(): void
    {
        $first = $this->item('one.mp3', 'a');
        $second = $this->item('two.mp3', 'b');

        DuplicateDecision::record($first->id, $second->id, DuplicateStatus::Kept);
        DuplicateDecision::record($second->id, $first->id, DuplicateStatus::Merged);

        $this->assertSame(1, DuplicateDecision::count());
        $this->assertSame(DuplicateStatus::Merged, DuplicateDecision::first()->decision);
    }

    /**
     * A merged row stays out of the sweep.
     *
     * Merging repoints the redundant row at the surviving file, so the row now
     * shares a path — and bytes — with its original. Searching it would match a
     * third copy and drag a settled row back into review for a file it does not
     * have its own copy of.
     */
    public function test_a_merged_row_is_not_swept(): void
    {
        $this->item('original.mp3', 'identical bytes');
        $copy = $this->item('copy.mp3', 'identical bytes');

        $this->detector->check($copy);
        $this->assertTrue($this->detector->merge($copy->fresh()));
        $this->assertSame(DuplicateStatus::Merged, $copy->fresh()->duplicate_status);

        // Another copy arrives; the merged row must stay out of it.
        $this->item('late.mp3', 'identical bytes');

        app(DetectDuplicatesJob::class, ['type' => null])->handle($this->detector);

        $this->assertSame(
            DuplicateStatus::Merged,
            $copy->fresh()->duplicate_status,
            'A merged row must not be dragged back into review.'
        );
    }

    /**
     * And the sweep itself reaches kept rows.
     *
     * The detector can be as willing as it likes; if the job's query still
     * filters them out, nothing changes in practice.
     */
    public function test_the_sweep_includes_kept_rows(): void
    {
        $this->item('original.mp3', 'identical bytes');
        $copy = $this->item('copy.mp3', 'identical bytes');

        $this->detector->check($copy);
        $this->detector->keepBoth($copy);

        $late = $this->item('late.mp3', 'identical bytes');

        app(DetectDuplicatesJob::class, ['type' => null])->handle($this->detector);

        $this->assertSame(
            DuplicateStatus::Pending,
            $copy->fresh()->duplicate_status,
            'The sweep must reach a kept row, or the detector never sees it.'
        );

        $this->assertNotNull($late->fresh());
    }

    private function item(string $filename, string $contents): MediaItem
    {
        $path = 'media/unsorted/'.$filename;
        Storage::disk('local')->put($path, $contents);

        $item = MediaItem::create([
            'user_id' => $this->user->id,
            'type' => MediaItemType::Music,
            'title' => pathinfo($filename, PATHINFO_FILENAME),
            'file_path' => Storage::disk('local')->path($path),
            'owned' => true,
        ]);

        $this->detector->ensureHashed($item);

        return $item->fresh();
    }
}
