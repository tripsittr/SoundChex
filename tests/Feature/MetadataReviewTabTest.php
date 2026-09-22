<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace Tests\Feature;

use App\Enums\DuplicateStatus;
use App\Enums\MediaItemType;
use App\Enums\ProcessingStatus;
use App\Filament\Resources\Duplicates\Pages\ListDuplicates;
use App\Jobs\EnrichMediaItemJob;
use App\Models\MediaItem;
use App\Models\Profile;
use App\Models\User;
use App\Services\CurrentProfile;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The Metadata tab of the Review hub (S-277): the items the pipeline was unsure
 * about — the ones that used to show "Needs Review" in the music list but had no
 * home on the review page — are listed here, and the tab renders driven the way
 * the browser drives it, not just asserted through the query.
 */
class MetadataReviewTabTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();

        // Access is by profile, not by user: the owner profile administers the
        // library and reaches every content screen (see RestrictsToAdmins).
        $owner = Profile::create([
            'user_id' => $this->user->id,
            'name' => 'Owner',
            'is_owner' => true,
        ]);

        $this->actingAs($this->user);
        app(CurrentProfile::class)->switchTo($owner->id);
        Filament::setCurrentPanel('admin');
    }

    public function test_the_metadata_tab_lists_flagged_items_and_not_clean_ones(): void
    {
        $flagged = $this->item('Unsure Track', ProcessingStatus::NeedsReview);
        $clean = $this->item('Fine Track', ProcessingStatus::Complete);

        Livewire::test(ListDuplicates::class)
            ->set('activeTab', 'metadata')
            ->assertCanSeeTableRecords([$flagged])
            ->assertCanNotSeeTableRecords([$clean]);
    }

    public function test_re_enrich_queues_the_pipeline_again(): void
    {
        Queue::fake();

        $flagged = $this->item('Unsure Track', ProcessingStatus::NeedsReview);

        Livewire::test(ListDuplicates::class)
            ->set('activeTab', 'metadata')
            ->callAction(TestAction::make('reenrich')->table($flagged))
            ->assertHasNoErrors();

        Queue::assertPushed(EnrichMediaItemJob::class);
    }

    public function test_marking_reviewed_clears_the_flag_and_stamps_reviewed_at(): void
    {
        $flagged = $this->item('Unsure Track', ProcessingStatus::NeedsReview);

        Livewire::test(ListDuplicates::class)
            ->set('activeTab', 'metadata')
            ->callAction(TestAction::make('markReviewed')->table($flagged))
            ->assertHasNoErrors();

        $fresh = $flagged->fresh();
        $this->assertSame(ProcessingStatus::Complete, $fresh->processing_status);
        // The human-reviewed stamp is what stops a later re-enrichment re-flagging
        // it (S-302).
        $this->assertNotNull($fresh->reviewed_at);
    }

    public function test_reenrichment_does_not_reflag_a_human_reviewed_item(): void
    {
        // The bug (S-302): a bulk re-enrich marched through the library and sent
        // already-reviewed songs back to the review queue. With reviewed_at set,
        // the job must keep the item complete even when the pipeline flags review.
        $item = $this->item('Reviewed Track', ProcessingStatus::Complete);
        $item->forceFill(['reviewed_at' => now()])->saveQuietly();

        // A pipeline that always asks for review, as an ambiguous match would.
        $this->app->bind(\App\Services\Metadata\MetadataPipeline::class, function () {
            return new class extends \App\Services\Metadata\MetadataPipeline
            {
                public function __construct() {}

                public function run(MediaItem $item): void
                {
                    $item->update(['processing_status' => ProcessingStatus::NeedsReview]);
                }
            };
        });

        (new EnrichMediaItemJob($item->id))->handle(
            app(\App\Services\Metadata\MetadataPipeline::class),
            app(\App\Services\LibraryOrganizer::class),
            app(\App\Services\MetadataHistory::class),
        );

        // Stayed complete — the human's decision was respected.
        $this->assertSame(ProcessingStatus::Complete, $item->fresh()->processing_status);
    }

    public function test_the_merge_action_does_not_leak_onto_the_metadata_tab_after_visiting_duplicates(): void
    {
        // Each tab is a different kind of review with different actions. Merge
        // belongs to the Duplicates tab; re-enrich to Metadata. Switching between
        // them must rebuild the table, or the previous tab's actions linger — the
        // bug where the Metadata tab offered "Merge selected".
        $this->item('Unsure Track', ProcessingStatus::NeedsReview);

        $original = MediaItem::create([
            'user_id' => $this->user->id, 'type' => MediaItemType::Music,
            'title' => 'Original', 'owned' => true,
        ]);
        MediaItem::create([
            'user_id' => $this->user->id, 'type' => MediaItemType::Music,
            'title' => 'A Duplicate', 'owned' => true,
            'duplicate_status' => DuplicateStatus::Pending, 'duplicate_of_id' => $original->id,
        ]);

        $component = Livewire::test(ListDuplicates::class);

        // Metadata tab: re-enrich, never merge.
        $component->assertSee('Re-enrich')->assertDontSee('Merge selected');

        // Visit Duplicates, where merge is correct...
        $component->set('activeTab', 'pending')->assertSee('Merge selected');

        // ...then back to Metadata: merge must be gone again.
        $component->set('activeTab', 'metadata')
            ->assertDontSee('Merge selected')
            ->assertSee('Re-enrich');
    }

    private function item(string $title, ProcessingStatus $status): MediaItem
    {
        $item = MediaItem::create([
            'user_id' => $this->user->id,
            'type' => MediaItemType::Music,
            'title' => $title,
            'owned' => true,
        ]);

        $item->musicMetadata()->create(['artist' => 'An Artist']);
        $item->forceFill(['processing_status' => $status])->save();

        return $item->fresh();
    }
}
