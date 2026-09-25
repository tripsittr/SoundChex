<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace Tests\Feature;

use App\Enums\MediaItemType;
use App\Models\MediaItem;
use App\Models\Profile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * A title cannot write its own response headers.
 *
 * `Content-Disposition` carried the item's title through `addslashes()`, which
 * escapes quotes and leaves CRLF alone. A title is not this server's to
 * choose: it comes from a file's embedded tags, or from TMDB, MusicBrainz or
 * Open Library. A newline in one would have closed the header and begun
 * another — header injection, with the whole library as the delivery
 * mechanism.
 *
 * Nothing in the library carries a newline today, which is exactly why this
 * needs a test rather than an inspection.
 */
class HeaderInjectionTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Profile $profile;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->profile = Profile::create([
            'user_id' => $this->user->id,
            'name' => 'Owner',
            'is_owner' => true,
        ]);
    }

    /** A title that tries to end its header and start another. */
    private const HOSTILE = "Innocent\r\nX-Injected: yes";

    private function actAsOwner(): self
    {
        return $this->actingAs($this->user)->withSession([
            'profile_id' => $this->profile->id,
            'profile_unlocked_at' => now()->timestamp,
        ]);
    }

    public function test_a_track_title_cannot_inject_a_response_header(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('media/track.mp3', 'not really audio');

        $item = MediaItem::create([
            'user_id' => $this->user->id,
            'type' => MediaItemType::Music,
            'title' => self::HOSTILE,
            'file_path' => Storage::disk('local')->path('media/track.mp3'),
            'owned' => true,
        ]);

        $response = $this->actAsOwner()->get("/app/item/{$item->id}/stream");

        $disposition = (string) $response->headers->get('Content-Disposition');

        $this->assertStringNotContainsString("\r", $disposition, 'no carriage return reaches the header');
        $this->assertStringNotContainsString("\n", $disposition, 'no newline reaches the header');
        $this->assertNull($response->headers->get('X-Injected'), 'the title did not create a header');
    }

    public function test_a_book_title_cannot_inject_a_response_header(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('media/book.epub', 'not really an epub');

        $item = MediaItem::create([
            'user_id' => $this->user->id,
            'type' => MediaItemType::Book,
            'title' => self::HOSTILE,
            'file_path' => Storage::disk('local')->path('media/book.epub'),
            'owned' => true,
        ]);

        $response = $this->actAsOwner()->get("/app/read/{$item->id}/file");

        $disposition = (string) $response->headers->get('Content-Disposition');

        $this->assertStringNotContainsString("\r", $disposition);
        $this->assertStringNotContainsString("\n", $disposition);
        $this->assertNull($response->headers->get('X-Injected'));
    }

    public function test_an_ordinary_title_still_reaches_the_client(): void
    {
        // The fix must not make every download called "media" — the encoded
        // form is what a browser actually reads for the filename.
        Storage::fake('local');
        Storage::disk('local')->put('media/ordinary.mp3', 'not really audio');

        $item = MediaItem::create([
            'user_id' => $this->user->id,
            'type' => MediaItemType::Music,
            'title' => 'Stressed Out',
            'file_path' => Storage::disk('local')->path('media/ordinary.mp3'),
            'owned' => true,
        ]);

        $disposition = (string) $this->actAsOwner()
            ->get("/app/item/{$item->id}/stream")
            ->headers->get('Content-Disposition');

        $this->assertStringContainsString('Stressed', $disposition);
    }
    /* --------------------------------------- a slash in a title (S-393) --- */

    /**
     * Builds a track with an awkward title and returns its stream response.
     */
    private function streamTitled(string $title): \Illuminate\Testing\TestResponse
    {
        Storage::fake('local');
        Storage::disk('local')->put('media/track.mp3', 'not really audio');

        $item = MediaItem::create([
            'user_id' => $this->user->id,
            'type' => MediaItemType::Music,
            'title' => $title,
            'file_path' => Storage::disk('local')->path('media/track.mp3'),
            'owned' => true,
        ]);

        return $this->actAsOwner()->get("/app/item/{$item->id}/stream");
    }

    public function test_a_slash_in_a_title_does_not_break_the_stream(): void
    {
        // Symfony refuses a filename containing a slash — it throws rather
        // than escaping — so "AM/PM" returned a 500 before a byte was sent and
        // every client reported the track as simply not playing. 33 tracks in
        // one real library were affected.
        $this->streamTitled('AM/PM')->assertOk();
    }

    public function test_a_backslash_in_a_title_does_not_break_the_stream(): void
    {
        $this->streamTitled('AC\\DC Tribute')->assertOk();
    }

    public function test_the_filename_keeps_the_title_readable(): void
    {
        // A separator becomes a dash rather than vanishing: "AM-PM" reads as
        // the track, where "AMPM" reads as a typo.
        $disposition = (string) $this->streamTitled('AM/PM')
            ->headers->get('Content-Disposition');

        $this->assertStringContainsString('AM-PM', $disposition);
    }

    public function test_a_title_that_sanitises_to_nothing_still_has_a_filename(): void
    {
        // An empty filename is its own kind of broken.
        $disposition = (string) $this->streamTitled('///')
            ->headers->get('Content-Disposition');

        $this->assertStringContainsString('filename', $disposition);
    }

}
