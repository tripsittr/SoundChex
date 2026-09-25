<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace Tests\Feature;

use App\Enums\MediaItemType;
use App\Enums\ProcessingStatus;
use App\Models\MediaItem;
use App\Models\Scopes\ResolvedScope;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The library shows only what is certain (S-396).
 *
 * The rule is easy to state and easy to half-implement: an audit found nine
 * user-facing queries that never reached `ContentGate`, plus every
 * route-model-bound `MediaItem`. These tests exist because the failure is
 * silent — a hidden item that is still streamable by id is not hidden, and
 * nothing about the page would tell you.
 */
class HidesUnresolvedItemsTest extends TestCase
{
    use RefreshDatabase;

    private ?User $owner = null;

    private function item(array $attributes = []): MediaItem
    {
        $this->owner ??= User::factory()->create();

        return MediaItem::unresolved()->create(array_merge([
            'user_id' => $this->owner->id,
            'title' => 'A Song',
            'type' => MediaItemType::Music,
            'file_path' => 'music/a-song.mp3',
            'processing_status' => ProcessingStatus::Complete,
        ], $attributes));
    }

    public function test_a_complete_item_is_visible(): void
    {
        $item = $this->item();

        $this->assertNotNull(MediaItem::find($item->id));
        $this->assertFalse($item->isUnresolved());
    }

    /**
     * Every state that is not `complete` hides the item. Written as a loop
     * over the enum rather than four cases so a status added later fails here
     * until someone decides which side of the line it is on.
     */
    public function test_every_unresolved_status_hides_the_item(): void
    {
        foreach (ProcessingStatus::cases() as $status) {
            $item = $this->item(['processing_status' => $status]);

            $visible = MediaItem::find($item->id) !== null;

            $this->assertSame(
                $status === ProcessingStatus::Complete,
                $visible,
                "{$status->value} should ".($status === ProcessingStatus::Complete ? 'be' : 'not be').' visible.',
            );
        }
    }

    public function test_a_missing_file_hides_the_item(): void
    {
        $item = $this->item(['file_missing' => true]);

        $this->assertNull(MediaItem::find($item->id));
        $this->assertTrue($item->isUnresolved());
    }

    /**
     * The point of the flag rather than a delete: the row is still there, so
     * restoring the file and re-scanning brings the item back with its plays,
     * playlists and progress intact.
     */
    public function test_a_hidden_item_is_still_in_the_database(): void
    {
        $item = $this->item(['file_missing' => true]);

        $this->assertNotNull(MediaItem::unresolved()->find($item->id));
        $this->assertDatabaseHas('media_items', ['id' => $item->id]);
    }

    public function test_clearing_the_flag_brings_the_item_back(): void
    {
        $item = $this->item(['file_missing' => true]);
        $this->assertNull(MediaItem::find($item->id));

        MediaItem::unresolved()->whereKey($item->id)->update(['file_missing' => false]);

        $this->assertNotNull(MediaItem::find($item->id));
    }

    /**
     * The one that matters most. A hidden item that can still be fetched by
     * id is not hidden — and route-model binding is a `findOrFail`, which is
     * exactly what a global scope covers and a query-builder gate does not.
     */
    public function test_a_hidden_item_cannot_be_streamed_by_id(): void
    {
        $user = User::factory()->create();
        $item = $this->item(['processing_status' => ProcessingStatus::NeedsReview]);

        // The real bound routes, not a made-up URL: a test that requests a
        // path with no route passes whether or not the scope exists, which
        // is how this one first "passed" while proving nothing.
        $this->actingAs($user)
            ->get("/app/item/{$item->id}")
            ->assertNotFound();

        $this->actingAs($user)
            ->get("/app/item/{$item->id}/stream")
            ->assertNotFound();
    }

    public function test_the_api_library_omits_hidden_items(): void
    {
        $user = User::factory()->create();
        $visible = $this->item(['title' => 'Visible']);
        $hidden = $this->item(['title' => 'Hidden', 'processing_status' => ProcessingStatus::NeedsReview]);

        $response = $this->actingAs($user)->getJson('/api/v1/library');
        $response->assertOk();

        $ids = collect($response->json('items') ?? $response->json('data') ?? [])->pluck('id');

        $this->assertTrue($ids->contains($visible->id), 'The complete item should sync.');
        $this->assertFalse($ids->contains($hidden->id), 'The needs-review item should not reach the device.');
    }

    /**
     * The admin panel's job is to find these, so it must see what the library
     * hides. This is the direction the mistake runs in: forgetting an opt-out
     * empties an admin screen rather than leaking data, and this test is what
     * catches that.
     */
    public function test_the_admin_query_still_sees_hidden_items(): void
    {
        $hidden = $this->item(['processing_status' => ProcessingStatus::NeedsReview]);

        $found = MediaItem::unresolved()
            ->where('processing_status', ProcessingStatus::NeedsReview)
            ->pluck('id');

        $this->assertTrue($found->contains($hidden->id));
    }

    public function test_the_scope_can_be_dropped_explicitly(): void
    {
        $hidden = $this->item(['file_missing' => true]);

        $found = MediaItem::query()
            ->withoutGlobalScope(ResolvedScope::class)
            ->whereKey($hidden->id)
            ->first();

        $this->assertNotNull($found);
    }
}
