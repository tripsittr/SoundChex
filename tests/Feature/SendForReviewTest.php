<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace Tests\Feature;

use App\Enums\MediaItemType;
use App\Enums\ProcessingStatus;
use App\Enums\ReviewReason;
use App\Filament\Resources\Duplicates\DuplicateResource;
use App\Filament\Resources\Duplicates\DuplicatesTable;
use App\Models\MediaItem;
use App\Models\MediaItemReport;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * "This item is wrong, and here is why" (S-398).
 *
 * Reporting hides the item from the library, which is a real consequence for
 * a single tap — the apps confirm first and say so. These tests pin the parts
 * that consequence depends on: that it hides, that a second reporter is still
 * heard, and that clearing the review puts it back.
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

    private function item(array $attributes = []): MediaItem
    {
        return MediaItem::unresolved()->create(array_merge([
            'user_id' => $this->user->id,
            'title' => 'A Song',
            'type' => MediaItemType::Music,
            'file_path' => 'music/a-song.mp3',
            'processing_status' => ProcessingStatus::Complete,
        ], $attributes));
    }

    public function test_reporting_an_item_records_the_reason(): void
    {
        $item = $this->item();

        $response = $this->actingAs($this->user)->postJson(
            "/api/v1/items/{$item->id}/review",
            ['reason' => 'file', 'note' => 'Plays as silence'],
        );

        $response->assertCreated();

        $report = MediaItemReport::firstOrFail();

        $this->assertSame(ReviewReason::File, $report->reason);
        $this->assertSame('Plays as silence', $report->note);
        $this->assertSame($item->id, $report->media_item_id);
        $this->assertTrue($report->isOpen());
    }

    /**
     * The consequence the apps warn about. If this stops being true, the
     * warning they show becomes a lie.
     */
    public function test_reporting_hides_the_item_from_the_library(): void
    {
        $item = $this->item();
        $this->assertNotNull(MediaItem::find($item->id), 'Visible before reporting.');

        $this->actingAs($this->user)
            ->postJson("/api/v1/items/{$item->id}/review", ['reason' => 'metadata'])
            ->assertCreated();

        $this->assertNull(MediaItem::find($item->id), 'Hidden after reporting.');
        $this->assertSame(
            ProcessingStatus::NeedsReview,
            MediaItem::unresolved()->find($item->id)->processing_status,
        );
    }

    /**
     * The reason the endpoint takes an id instead of a route-model binding:
     * the binding is scoped, so once the first report hid the item every
     * later reporter would get a 404. Two people hitting the same broken file
     * is worth hearing twice.
     */
    public function test_a_second_person_can_report_an_already_hidden_item(): void
    {
        $item = $this->item();

        $this->actingAs($this->user)
            ->postJson("/api/v1/items/{$item->id}/review", ['reason' => 'file'])
            ->assertCreated();

        $other = User::factory()->create();

        $this->actingAs($other)
            ->postJson("/api/v1/items/{$item->id}/review", ['reason' => 'cover'])
            ->assertCreated();

        $this->assertSame(2, MediaItemReport::count());
    }

    public function test_the_note_is_optional_but_the_reason_is_not(): void
    {
        $item = $this->item();

        $this->actingAs($this->user)
            ->postJson("/api/v1/items/{$item->id}/review", ['reason' => 'duplicate'])
            ->assertCreated();

        $this->actingAs($this->user)
            ->postJson("/api/v1/items/{$item->id}/review", [])
            ->assertStatus(422);

        $this->actingAs($this->user)
            ->postJson("/api/v1/items/{$item->id}/review", ['reason' => 'not-a-reason'])
            ->assertStatus(422);
    }

    public function test_reporting_clears_an_earlier_reviewed_stamp(): void
    {
        // An admin's "I looked at this and it is fine" does not survive
        // someone saying it is still wrong.
        $item = $this->item(['reviewed_at' => now()->subDay()]);

        $this->actingAs($this->user)
            ->postJson("/api/v1/items/{$item->id}/review", ['reason' => 'metadata'])
            ->assertCreated();

        $this->assertNull(MediaItem::unresolved()->find($item->id)->reviewed_at);
    }

    public function test_reporting_requires_authentication(): void
    {
        $item = $this->item();

        $this->postJson("/api/v1/items/{$item->id}/review", ['reason' => 'file'])
            ->assertUnauthorized();
    }

    public function test_a_reported_item_appears_on_the_admin_review_screen(): void
    {
        // The whole point of reporting is that an admin sees it. An item with
        // no duplicate and no scanner complaint reaches that screen only
        // because a person put it there.
        $item = $this->item();

        $this->actingAs($this->user)
            ->postJson("/api/v1/items/{$item->id}/review", ['reason' => 'cover'])
            ->assertCreated();

        $ids = DuplicateResource::getEloquentQuery()
            ->pluck('id');

        $this->assertTrue($ids->contains($item->id));
    }

    public function test_marking_it_reviewed_closes_the_report_and_restores_the_item(): void
    {
        $item = $this->item();

        $this->actingAs($this->user)
            ->postJson("/api/v1/items/{$item->id}/review", ['reason' => 'metadata'])
            ->assertCreated();

        $this->assertNull(MediaItem::find($item->id));

        // What the admin screen's "mark reviewed" action does.
        $record = MediaItem::unresolved()->findOrFail($item->id);
        $method = new \ReflectionMethod(
            DuplicatesTable::class,
            'stampReviewed',
        );
        $method->setAccessible(true);
        $method->invoke(null, $record);

        $this->assertNotNull(MediaItem::find($item->id), 'Back in the library.');
        $this->assertFalse(MediaItemReport::firstOrFail()->isOpen(), 'Report closed.');
    }

    public function test_an_unknown_item_is_a_404(): void
    {
        $this->actingAs($this->user)
            ->postJson('/api/v1/items/999999/review', ['reason' => 'file'])
            ->assertNotFound();
    }
}
