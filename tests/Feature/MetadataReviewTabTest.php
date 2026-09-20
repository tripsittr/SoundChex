<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace Tests\Feature;

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

    public function test_marking_reviewed_clears_the_flag(): void
    {
        $flagged = $this->item('Unsure Track', ProcessingStatus::NeedsReview);

        Livewire::test(ListDuplicates::class)
            ->set('activeTab', 'metadata')
            ->callAction(TestAction::make('markReviewed')->table($flagged))
            ->assertHasNoErrors();

        $this->assertSame(ProcessingStatus::Complete, $flagged->fresh()->processing_status);
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
