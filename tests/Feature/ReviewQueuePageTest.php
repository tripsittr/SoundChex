<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace Tests\Feature;

use App\Enums\DuplicateMatch;
use App\Enums\DuplicateStatus;
use App\Enums\MediaItemType;
use App\Enums\PipelineStage;
use App\Enums\PipelineState;
use App\Enums\ProcessingStatus;
use App\Filament\Pages\ReviewQueuePage;
use App\Jobs\Pipeline\RunPipelineStageJob;
use App\Models\MediaItem;
use App\Models\Profile;
use App\Models\User;
use App\Services\CurrentProfile;
use App\Services\Review\ReviewQueue;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The review queue (#480), built to the approved layout (#481).
 *
 * What matters here is that the four jobs stay **mutually exclusive** — an
 * item in two jobs means the counts lie and the same decision is offered twice
 * — and that every decision actually changes the item rather than only looking
 * like it did.
 */
class ReviewQueuePageTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();

        // The panel gates on the current *profile*, not the account, so a test
        // that does not choose one is testing the door rather than the page.
        $owner = Profile::create([
            'user_id' => $this->user->id,
            'name' => 'Owner',
            'is_owner' => true,
        ]);

        $this->actingAs($this->user);
        app(CurrentProfile::class)->switchTo($owner->id);

        // Livewire renders the page outside a panel request, so the panel it
        // belongs to has to be named -- an HTTP test gets this from the route,
        // a component test does not.
        Filament::setCurrentPanel('admin');
    }

    /* ----------------------------------------------------------- gate --- */

    public function test_a_profile_without_library_administration_is_refused(): void
    {
        // a5's review note 2. The gate comes from RestrictsToAdmins and is
        // covered where that trait lives, so this page is transitively safe --
        // but a screen whose buttons merge duplicates and delete files should
        // assert its own door rather than inherit the claim. If somebody
        // later gives this page its own canAccess(), this is what catches a
        // mistake in it.
        $member = Profile::create([
            'user_id' => $this->user->id,
            'name' => 'Member',
            'is_owner' => false,
        ]);

        app(CurrentProfile::class)->switchTo($member->id);

        $this->assertFalse(
            $member->canAdministerLibrary(),
            'Precondition: a plain member holds neither library nor server administration.',
        );
        $this->assertFalse(
            ReviewQueuePage::canAccess(),
            'A profile that cannot administer the library must not reach a screen that merges duplicates and deletes files.',
        );
    }

    public function test_the_owner_profile_reaches_the_page(): void
    {
        // The other half: the owner cannot be locked out, or a household with
        // nobody able to review would need database surgery.
        $this->assertTrue(ReviewQueuePage::canAccess());
    }

    public function test_the_page_is_hidden_from_navigation_for_a_refused_profile(): void
    {
        // Cosmetic on its own -- canAccess() is the real gate -- but a menu
        // entry that 403s when clicked is worse than no entry.
        $member = Profile::create([
            'user_id' => $this->user->id,
            'name' => 'Member 2',
            'is_owner' => false,
        ]);

        app(CurrentProfile::class)->switchTo($member->id);

        $this->assertFalse(ReviewQueuePage::shouldRegisterNavigation());
    }

    /* ---------------------------------------------------------- queue --- */

    public function test_the_four_jobs_are_mutually_exclusive(): void
    {
        // One item per job, each also carrying a trait another job looks for,
        // so an item can only land in one place if the ordering is right.
        $this->unidentified();
        $this->pendingDuplicate();
        $this->coverReview();
        $this->fileProblem();

        $queue = app(ReviewQueue::class);
        $seen = [];

        foreach (array_keys(ReviewQueue::JOBS) as $job) {
            foreach ($queue->query($job)->pluck('id') as $id) {
                // The message is built before the assertion, because reading
                // $seen[$id] inside it is an undefined index on the happy path.
                $clash = $seen[$id] ?? null;

                $this->assertNull(
                    $clash,
                    "Item {$id} is in both {$clash} and {$job}; the counts would lie and the decision would be asked twice.",
                );

                $seen[$id] = $job;
            }
        }

        $this->assertCount(4, $seen, 'Each of the four items belongs to exactly one job.');
    }

    public function test_a_pending_duplicate_is_a_duplicate_question_not_a_cover_one(): void
    {
        // Deciding which copy to keep settles the cover too, so asking both
        // would be asking twice.
        $item = $this->pendingDuplicate();
        $item->forceFill(['needs_cover_review' => true])->saveQuietly();

        $queue = app(ReviewQueue::class);

        $this->assertTrue($queue->query(ReviewQueue::DUPLICATES)->whereKey($item->id)->exists());
        $this->assertFalse($queue->query(ReviewQueue::COVERS)->whereKey($item->id)->exists());
    }

    public function test_every_job_is_counted_even_when_empty(): void
    {
        // The page dims an empty job rather than hiding it, so the controls do
        // not shift between visits. The user asked for "File problems" to keep
        // its slot: "Yes it does get a spot. Eventually file problems will arise."
        $counts = app(ReviewQueue::class)->counts();

        $this->assertSame(array_keys(ReviewQueue::JOBS), array_keys($counts));
        $this->assertSame(0, $counts[ReviewQueue::FILES]);
    }

    /* ----------------------------------------------------------- page --- */

    public function test_the_page_renders_with_the_first_item_selected(): void
    {
        $item = $this->unidentified();

        Livewire::test(ReviewQueuePage::class)
            ->assertOk()
            ->assertSet('job', ReviewQueue::IDENTIFY)
            ->assertSet('item', $item->id)
            ->assertSee($item->title);
    }

    public function test_the_page_states_the_question_in_words(): void
    {
        // The user's actual complaint: the old table showed a row and left you
        // to work out what was being asked.
        $item = $this->unidentified();
        $item->forceFill(['pipeline_error' => 'no source could identify this file'])->saveQuietly();

        Livewire::test(ReviewQueuePage::class)
            ->assertSee('No source could identify this file');
    }

    public function test_switching_job_moves_to_the_first_item_of_that_job(): void
    {
        $this->unidentified();
        $dupe = $this->pendingDuplicate();

        Livewire::test(ReviewQueuePage::class)
            ->call('openJob', ReviewQueue::DUPLICATES)
            ->assertSet('job', ReviewQueue::DUPLICATES)
            ->assertSet('item', $dupe->id);
    }

    public function test_an_unknown_job_falls_back_rather_than_erroring(): void
    {
        // The job is in the URL, so a hand-edited or stale link must not 500.
        Livewire::test(ReviewQueuePage::class, ['job' => 'nonsense'])
            ->assertOk()
            ->assertSet('job', ReviewQueue::IDENTIFY);
    }

    public function test_next_and_previous_wrap_around(): void
    {
        $first = $this->unidentified('First');
        $second = $this->unidentified('Second');

        Livewire::test(ReviewQueuePage::class)
            ->assertSet('item', $first->id)
            ->call('next')->assertSet('item', $second->id)
            ->call('next')->assertSet('item', $first->id)
            ->call('previous')->assertSet('item', $second->id);
    }

    /* ------------------------------------------------------ decisions --- */

    public function test_accepting_an_item_stamps_reviewed_at_and_leaves_the_queue(): void
    {
        // reviewed_at is what every other path checks so a later re-enrichment
        // cannot drag the item back -- the S-302 trap.
        $item = $this->unidentified();

        Livewire::test(ReviewQueuePage::class)->call('accept', $item->id);

        $this->assertNotNull($item->fresh()->reviewed_at);
        $this->assertFalse(
            app(ReviewQueue::class)->query(ReviewQueue::IDENTIFY)->whereKey($item->id)->exists(),
            'A judged item must leave the queue, or it is offered forever.',
        );
    }

    public function test_re_identifying_sends_the_item_back_to_that_stage(): void
    {
        // Queued rather than run, so the assertion is about what this action
        // does and not about what identification then concludes. Without the
        // fake the job executes inline, identification finds nothing for a
        // fixture with no real tags, and the item is parked again -- correct
        // behaviour that happens to erase the state being tested.
        Queue::fake();

        $item = $this->unidentified();

        Livewire::test(ReviewQueuePage::class)->call('reidentify', $item->id);

        $fresh = $item->fresh();

        $this->assertSame(PipelineStage::Identified, $fresh->pipeline_stage);
        $this->assertSame(PipelineState::Queued, $fresh->pipeline_state);
        $this->assertSame(0, $fresh->pipeline_attempts, 'A person changed something, so past failures say nothing about this run.');

        Queue::assertPushed(RunPipelineStageJob::class);
    }

    public function test_re_identifying_an_item_that_still_cannot_be_identified_parks_it_again(): void
    {
        // The other half, with the queue running for real: re-identifying is
        // not a promise of success. An item nothing can identify comes back to
        // the queue with its reason, rather than being filed on a guess or
        // disappearing.
        $item = $this->unidentified();

        Livewire::test(ReviewQueuePage::class)->call('reidentify', $item->id);

        $fresh = $item->fresh();

        $this->assertSame(PipelineState::Waiting, $fresh->pipeline_state);
        $this->assertNotEmpty($fresh->pipeline_error, 'A parked item must say why.');
    }

    public function test_keeping_both_copies_resolves_the_pair_without_touching_either_file(): void
    {
        // The rule the user set (#476): if Spotify has fifteen versions, so do
        // we. Keeping both must leave both files alone.
        $item = $this->pendingDuplicate();
        $here = $item->absoluteFilePath();
        $there = $item->duplicateOf->absoluteFilePath();

        Livewire::test(ReviewQueuePage::class)
            ->call('openJob', ReviewQueue::DUPLICATES)
            ->call('keepBoth', $item->id);

        $this->assertFileExists($here);
        $this->assertFileExists($there);
        $this->assertNotSame(DuplicateStatus::Pending, $item->fresh()->duplicate_status);
    }

    public function test_confirming_a_cover_clears_the_flag(): void
    {
        $item = $this->coverReview();

        Livewire::test(ReviewQueuePage::class)
            ->call('openJob', ReviewQueue::COVERS)
            ->call('acceptCover', $item->id);

        $this->assertFalse((bool) $item->fresh()->needs_cover_review);
    }

    public function test_confirming_all_covers_clears_every_flag(): void
    {
        // Bulk only where it is safe: the audio decision is already made on
        // each of these, so confirming them together changes nothing on disk.
        $this->coverReview('One');
        $this->coverReview('Two');
        $this->coverReview('Three');

        Livewire::test(ReviewQueuePage::class)
            ->call('openJob', ReviewQueue::COVERS)
            ->call('acceptAllCovers');

        $this->assertSame(0, app(ReviewQueue::class)->counts()[ReviewQueue::COVERS]);
    }

    public function test_skipping_changes_nothing_about_the_item(): void
    {
        $item = $this->unidentified();
        $before = $item->fresh()->only(['processing_status', 'pipeline_state', 'reviewed_at']);

        Livewire::test(ReviewQueuePage::class)->call('skip', $item->id);

        $this->assertSame($before, $item->fresh()->only(['processing_status', 'pipeline_state', 'reviewed_at']));
    }

    /* -------------------------------------------------------- edition --- */

    public function test_an_edition_suffix_is_surfaced_not_stripped(): void
    {
        // Three of the real queue's items are "Psycho Killer - Acoustic",
        // "1979 - Remastered 2012" and "Murder on the Dancefloor - triple j
        // Like A Version". Stripping after " - " would merge recordings that
        // must stay apart (#476), so the page shows it as evidence.
        $item = $this->unidentified('Psycho Killer - Acoustic');

        $this->assertSame('Acoustic', $item->editionSuffix());

        Livewire::test(ReviewQueuePage::class)
            ->assertSee('Acoustic')
            ->assertSee('kept, not stripped');
    }

    public function test_a_title_with_no_edition_reports_none(): void
    {
        $this->assertNull($this->unidentified('Me & My Dog')->editionSuffix());
        $this->assertNull($this->unidentified('Mr. Brightside')->editionSuffix());
    }

    /* -------------------------------------------------------- helpers --- */

    private function unidentified(string $title = 'Unknown track'): MediaItem
    {
        return $this->item($title, [
            'processing_status' => ProcessingStatus::NeedsReview,
            'pipeline_stage' => PipelineStage::Identified,
            'pipeline_state' => PipelineState::Waiting,
        ]);
    }

    private function pendingDuplicate(): MediaItem
    {
        $original = $this->item('Original', ['processing_status' => ProcessingStatus::Complete]);
        $copy = $this->item('Copy', ['processing_status' => ProcessingStatus::Complete]);

        $copy->forceFill([
            'duplicate_of_id' => $original->id,
            'duplicate_status' => DuplicateStatus::Pending,
            'duplicate_match' => DuplicateMatch::Isrc,
        ])->saveQuietly();

        return $copy->fresh();
    }

    private function coverReview(string $title = 'Cover me'): MediaItem
    {
        return $this->item($title, [
            'processing_status' => ProcessingStatus::Complete,
            'needs_cover_review' => true,
        ]);
    }

    private function fileProblem(): MediaItem
    {
        return $this->item('Broken', [
            'processing_status' => ProcessingStatus::Failed,
            'pipeline_stage' => PipelineStage::Filed,
            'pipeline_state' => PipelineState::Failed,
            'pipeline_error' => 'could not file: the target was taken',
        ]);
    }

    /** @param array<string, mixed> $state */
    /* ----------------------------------------------------- cover art ----- */

    public function test_the_queue_list_shows_covers_not_a_row_of_identical_icons(): void
    {
        // The part the first fix missed. The list drew a type icon for *every*
        // row regardless of artwork, so a queue of 89 cover-art questions
        // showed 89 identical music notes -- in the one view where the artwork
        // is the thing being judged. Fixing the detail pane alone left the
        // screen looking unchanged, which is how the owner found it still
        // broken.
        $first = $this->unidentified('First Track');
        $first->forceFill(['cover_image_url' => 'artwork/Someone/An Album/First-1.jpg'])->saveQuietly();

        $second = $this->unidentified('Second Track');
        $second->forceFill(['cover_image_url' => 'artwork/Someone/An Album/Second-2.jpg'])->saveQuietly();

        $html = Livewire::test(ReviewQueuePage::class, ['job' => ReviewQueue::IDENTIFY])->html();

        // Both rows, not just the selected one in the detail pane.
        $this->assertStringContainsString('First-1.jpg', $html);
        $this->assertStringContainsString('Second-2.jpg', $html);

        // Filament inlines the icon as an <svg>, so the icon *name* never
        // reaches the HTML -- assert on the placeholder <span> wrapper that
        // only renders when there is no cover.
        $this->assertSame(
            0,
            substr_count($html, 'items-center justify-center rounded bg-gray-100'),
            'A row with artwork still fell back to the icon placeholder.',
        );
    }

    public function test_a_queue_row_without_artwork_keeps_its_type_icon(): void
    {
        // The fallback has to survive: a row with no cover needs *something*,
        // and an empty 36px gap reads as a broken image.
        $this->unidentified('No Art Here');

        $html = Livewire::test(ReviewQueuePage::class, ['job' => ReviewQueue::IDENTIFY])->html();

        // The placeholder wrapper, which is what distinguishes "no cover"
        // from "cover" -- the inlined svg itself is identical either way.
        $this->assertStringContainsString('items-center justify-center rounded bg-gray-100', $html);
        $this->assertSame(0, preg_match('/<img[^>]*src=""/', $html));
    }

    public function test_the_cover_is_shown_on_every_job_not_only_the_cover_job(): void
    {
        // The reported bug. The cover was drawn inside the `covers` branch
        // alone, so on a library with nothing in that queue the review page
        // showed no artwork at all -- 38 items to identify and 6 duplicates to
        // judge, every one rendered as text while valid artwork sat on disk.
        // Recognising a record by its sleeve is most of how somebody answers
        // these questions.
        $item = $this->unidentified();
        $item->forceFill(['cover_image_url' => 'artwork/Someone/An Album/Cover-1.jpg'])->saveQuietly();

        Livewire::test(ReviewQueuePage::class, ['job' => ReviewQueue::IDENTIFY])
            ->assertSee('An%20Album', false);
    }

    public function test_a_cover_path_with_spaces_is_url_encoded(): void
    {
        // `Storage::disk('public')->url()` does NOT encode the path, and these
        // paths are built from artist and album names. Emitted raw, the browser
        // never fetched them -- the second half of the same bug. `coverUrl()`
        // rawurlencodes each segment, so the assertion is that no raw space
        // reaches a src attribute.
        $item = $this->unidentified();
        $item->forceFill([
            'cover_image_url' => 'artwork/The Band, Live/Album Name/Spaced-2.jpg',
        ])->saveQuietly();

        $html = Livewire::test(ReviewQueuePage::class, ['job' => ReviewQueue::IDENTIFY])->html();

        $this->assertStringContainsString('Album%20Name', $html);

        $this->assertSame(
            0,
            preg_match('/src="[^"]*[ \t][^"]*"/', $html),
            'A src attribute contains an unencoded space, which the browser will not fetch.',
        );
    }

    public function test_a_non_breaking_space_in_a_cover_path_is_encoded(): void
    {
        // Found in this library for real: two artwork directories that look
        // identical, one holding a NO-BREAK SPACE (U+00A0). The row pointed at
        // the right file all along; only the encoding was wrong. A plain
        // str_replace(' ', '%20') would miss it, which is why this is its own
        // case.
        $item = $this->unidentified();
        $item->forceFill([
            'cover_image_url' => "artwork/Some\u{A0}Artist/Unknown Album/West End-3.jpg",
        ])->saveQuietly();

        $html = Livewire::test(ReviewQueuePage::class, ['job' => ReviewQueue::IDENTIFY])->html();

        // %C2%A0 is U+00A0 percent-encoded as UTF-8.
        $this->assertStringContainsString('Some%C2%A0Artist', $html);
    }

    public function test_an_item_with_no_cover_renders_without_a_broken_image(): void
    {
        // No placeholder <img> with an empty src: a browser resolves src="" to
        // the current page and fetches the whole document as an image.
        $this->unidentified('Artless');

        $html = Livewire::test(ReviewQueuePage::class, ['job' => ReviewQueue::IDENTIFY])
            ->assertSee('Artless')
            ->html();

        $this->assertSame(0, preg_match('/<img[^>]*src=""/', $html));
    }

    public function test_both_covers_are_shown_when_comparing_duplicates(): void
    {
        // "Is this the same record?" is answered by eye faster than by
        // comparing two file paths, and differing artwork is often the
        // clearest sign two copies are different releases rather than
        // duplicates.
        $copy = $this->pendingDuplicate();

        $copy->forceFill([
            'cover_image_url' => 'artwork/Someone/Second Release/Copy-11.jpg',
        ])->saveQuietly();

        MediaItem::withoutGlobalScopes()
            ->whereKey($copy->duplicate_of_id)
            ->first()
            ?->forceFill(['cover_image_url' => 'artwork/Someone/First Release/Original-10.jpg'])
            ->saveQuietly();

        $html = Livewire::test(ReviewQueuePage::class, ['job' => ReviewQueue::DUPLICATES])->html();

        $this->assertStringContainsString('First%20Release', $html, "The existing copy's cover is missing.");
        $this->assertStringContainsString('Second%20Release', $html, "The new copy's cover is missing.");
    }

    private function item(string $title, array $state): MediaItem
    {
        $path = 'media/unsorted/'.str()->slug($title).'-'.fake()->unique()->numberBetween(1, 99999).'.mp3';
        Storage::disk('local')->put($path, 'audio');

        $item = MediaItem::create([
            'user_id' => $this->user->id,
            'type' => MediaItemType::Music,
            'title' => $title,
            'file_path' => Storage::disk('local')->path($path),
            'owned' => true,
        ]);

        $item->musicMetadata()->create(['artist' => 'Someone']);
        $item->forceFill($state)->saveQuietly();

        return $item->fresh();
    }
}
