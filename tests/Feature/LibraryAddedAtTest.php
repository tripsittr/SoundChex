<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace Tests\Feature;

use App\Enums\MediaItemType;
use App\Enums\ProcessingStatus;
use App\Models\MediaItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The catalogue says when an item was added, not only when it changed (S-451).
 *
 * "Recently added" on the clients was built from whatever order the database
 * returned, because nothing in the payload answered the question. `updated_at`
 * was present and is the wrong field: an enrichment pass moves it on every
 * item it touches, so a row built on it fills with what the scanner last
 * looked at rather than what is new.
 */
class LibraryAddedAtTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_catalogue_says_when_an_item_was_added(): void
    {
        $user = User::factory()->create();

        $item = MediaItem::unresolved()->create([
            'user_id' => $user->id,
            'title' => 'A Film',
            'type' => MediaItemType::Movie,
            'file_path' => 'movies/a-film.mkv',
            'processing_status' => ProcessingStatus::Complete,
        ]);

        $response = $this->actingAs($user)->getJson('/api/v1/library');

        $response->assertOk();

        $row = collect($response->json('items'))->firstWhere('id', $item->id);

        $this->assertNotNull($row['added_at'] ?? null, 'the row carries an added_at');
        $this->assertSame(
            $item->created_at->toIso8601String(),
            $row['added_at'],
            'added_at is when the row was created, not when it was last touched',
        );
    }

    /**
     * The two are genuinely different fields, which is the whole point: an
     * item touched by enrichment long after import must still sort by when it
     * arrived.
     */
    public function test_added_at_does_not_move_when_the_item_changes(): void
    {
        $user = User::factory()->create();

        $item = MediaItem::unresolved()->create([
            'user_id' => $user->id,
            'title' => 'Old Import',
            'type' => MediaItemType::Movie,
            'file_path' => 'movies/old.mkv',
            'processing_status' => ProcessingStatus::Complete,
        ]);

        // Backdated after creation: Eloquent sets both timestamps on insert,
        // so passing created_at to create() is overwritten.
        $item->forceFill(['created_at' => now()->subYear()])->saveQuietly();

        // Then an ordinary edit, which moves updated_at and nothing else.
        $item->forceFill(['title' => 'Old Import, Enriched'])->save();
        $item->refresh();

        $row = collect($this->actingAs($user)->getJson('/api/v1/library')->json('items'))
            ->firstWhere('id', $item->id);

        $this->assertNotSame(
            $row['updated_at'],
            $row['added_at'],
            'a later edit moves updated_at and must leave added_at alone',
        );
    }
}
