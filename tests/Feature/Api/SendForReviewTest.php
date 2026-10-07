<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace Tests\Feature\Api;

use App\Enums\MediaItemType;
use App\Enums\ProcessingStatus;
use App\Enums\ReviewReason;
use App\Models\MediaItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Reporting an item puts it back in the review queue.
 *
 * The apps offer "Mark for review" with a reason. The point of that is not a
 * note in a table somewhere — it is that the item **re-enters review** with
 * the reason recorded, so the pipeline and the admin screens treat it as
 * something a person has to look at again.
 *
 * `reviewed_at` matters as much as the status: an item that was reviewed once
 * is stamped, and leaving the stamp would let a re-enrichment sweep decide it
 * had already been dealt with.
 */
class SendForReviewTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
    }

    private function film(ProcessingStatus $status = ProcessingStatus::Complete): MediaItem
    {
        return MediaItem::unresolved()->create([
            'user_id' => $this->user->id,
            'title' => 'A Film',
            'type' => MediaItemType::Movie,
            'file_path' => 'films/a.mp4',
            'processing_status' => $status,
            'reviewed_at' => now()->subDay(),
        ])->fresh();
    }

    public function test_reporting_an_item_flags_it_for_review(): void
    {
        $film = $this->film();

        $this->actingAs($this->user)
            ->postJson("/api/v1/items/{$film->id}/review", ['reason' => 'metadata'])
            ->assertCreated()
            ->assertJsonPath('reason', 'metadata');

        $fresh = $film->fresh();

        $this->assertSame(ProcessingStatus::NeedsReview, $fresh->processing_status);
    }

    /**
     * The stamp has to be cleared, or a sweep that skips already-reviewed
     * items would quietly pass over the thing just reported.
     */
    public function test_reporting_clears_the_reviewed_stamp(): void
    {
        $film = $this->film();

        $this->assertNotNull($film->reviewed_at, 'The fixture starts already reviewed.');

        $this->actingAs($this->user)
            ->postJson("/api/v1/items/{$film->id}/review", ['reason' => 'cover'])
            ->assertCreated();

        $this->assertNull($film->fresh()->reviewed_at);
    }

    /** The reason is recorded, not just the fact of a report. */
    public function test_the_chosen_reason_is_stored(): void
    {
        $film = $this->film();

        $this->actingAs($this->user)
            ->postJson("/api/v1/items/{$film->id}/review", [
                'reason' => 'file',
                'note' => 'Plays with no sound.',
            ])
            ->assertCreated();

        $report = $film->fresh()->reports()->latest('id')->first();

        $this->assertNotNull($report);
        $this->assertSame(ReviewReason::File, $report->reason);
        $this->assertSame('Plays with no sound.', $report->note);
    }

    /** Every reason the apps offer has to be accepted. */
    public function test_every_offered_reason_is_accepted(): void
    {
        foreach (ReviewReason::cases() as $reason) {
            $film = $this->film();

            $this->actingAs($this->user)
                ->postJson("/api/v1/items/{$film->id}/review", ['reason' => $reason->value])
                ->assertCreated();
        }
    }

    public function test_an_unknown_reason_is_refused(): void
    {
        $film = $this->film();

        $this->actingAs($this->user)
            ->postJson("/api/v1/items/{$film->id}/review", ['reason' => 'not-a-reason'])
            ->assertStatus(422);
    }

    public function test_it_requires_authentication(): void
    {
        $film = $this->film();

        $this->postJson("/api/v1/items/{$film->id}/review", ['reason' => 'metadata'])
            ->assertUnauthorized();
    }

    /**
     * A film that is already hidden can still be reported — an item in review
     * for one reason may be wrong for a second.
     */
    public function test_an_item_already_in_review_can_be_reported_again(): void
    {
        $film = $this->film(ProcessingStatus::NeedsReview);

        // Already reported once for something else.
        $this->actingAs($this->user)
            ->postJson("/api/v1/items/{$film->id}/review", ['reason' => 'metadata'])
            ->assertCreated();

        $this->actingAs($this->user)
            ->postJson("/api/v1/items/{$film->id}/review", ['reason' => 'duplicate'])
            ->assertCreated();

        // Both complaints are kept: an item wrong in two ways is wrong twice,
        // and collapsing them would lose one of the reasons.
        $this->assertSame(2, $film->fresh()->reports()->count());
    }
}
