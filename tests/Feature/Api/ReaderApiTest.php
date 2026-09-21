<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace Tests\Feature\Api;

use App\Enums\MediaItemType;
use App\Models\MediaItem;
use App\Models\Profile;
use App\Models\ReadingProgress;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The native app's book-reading endpoints (S-161): a book's format + resume
 * point, the file to render, and saving the reading position — token-authed and
 * content-gated.
 */
class ReaderApiTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Profile $owner;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $this->user = User::factory()->create();
        $this->owner = Profile::create([
            'user_id' => $this->user->id,
            'name' => 'Owner',
            'is_owner' => true,
        ]);
        Sanctum::actingAs($this->user, ['profile:'.$this->owner->id]);
    }

    public function test_it_returns_a_books_format_and_resume_point(): void
    {
        $book = $this->book();
        ReadingProgress::create([
            'media_item_id' => $book->id,
            'profile_id' => $this->owner->id,
            'user_id' => $this->user->id,
            'location' => 'epubcfi(/6/4!/4/10)',
            'percent' => 42,
        ]);

        $this->getJson(route('api.items.reader', $book))
            ->assertOk()
            ->assertJsonPath('format', 'epub')
            ->assertJsonPath('fileUrl', route('api.items.book', $book))
            ->assertJsonPath('progress.location', 'epubcfi(/6/4!/4/10)')
            ->assertJsonPath('progress.percent', 42);
    }

    public function test_it_serves_the_book_file_inline(): void
    {
        $book = $this->book();

        $this->get(route('api.items.book', $book))
            ->assertOk()
            ->assertHeader('Content-Type', 'application/epub+zip');
    }

    public function test_it_saves_and_refuses_stale_reading_progress(): void
    {
        $book = $this->book();

        $this->postJson(route('api.items.reader.progress', $book), [
            'location' => 'cfi-A', 'percent' => 50,
        ])->assertOk()->assertJsonPath('percent', 50);

        // A later, further position advances it.
        $this->postJson(route('api.items.reader.progress', $book), [
            'location' => 'cfi-B', 'percent' => 60, 'recorded_at' => now()->toIso8601String(),
        ])->assertOk()->assertJsonPath('percent', 60);

        // An old queued write (recorded before the stored one) is refused.
        $this->postJson(route('api.items.reader.progress', $book), [
            'location' => 'cfi-old', 'percent' => 10, 'recorded_at' => now()->subHour()->toIso8601String(),
        ])->assertOk()->assertJsonPath('stale', true)->assertJsonPath('percent', 60);
    }

    public function test_a_non_book_has_no_reader_endpoint(): void
    {
        $movie = MediaItem::create([
            'user_id' => $this->user->id,
            'type' => MediaItemType::Movie,
            'title' => 'A Film',
            'file_path' => 'library/a-film.mkv',
            'owned' => true,
        ]);

        $this->getJson(route('api.items.reader', $movie))->assertNotFound();
    }

    public function test_the_endpoint_passes_through_the_content_gate(): void
    {
        // Books are not rating-gated in this library (only films and shows carry a
        // certification the gate enforces), but the reader endpoints still run
        // every request through the gate, so any rule it grows applies here too.
        // With no cap, an owned book is reachable.
        $book = $this->book();

        $this->getJson(route('api.items.reader', $book))->assertOk();
    }

    private function book(): MediaItem
    {
        $path = 'books/a-novel.epub';
        Storage::disk('local')->put($path, 'PK-fake-epub-bytes');

        $book = MediaItem::create([
            'user_id' => $this->user->id,
            'type' => MediaItemType::Book,
            'title' => 'A Novel',
            'file_path' => $path,
            'owned' => true,
        ]);
        $book->bookMetadata()->create(['author' => 'An Author']);

        return $book;
    }
}
