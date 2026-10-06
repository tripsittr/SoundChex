<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace Tests\Feature;

use App\Enums\MatchConfidence;
use App\Enums\MediaItemType;
use App\Enums\ProcessingStatus;
use App\Models\MediaItem;
use App\Models\User;
use App\Services\LibraryOrganizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Music was exempt from the confidence gate entirely (#489).
 *
 * The stated reasoning — AGENTS.md rule 2 — is that a music file's artist and
 * album come from its own embedded tags, which are authoritative about the file
 * whatever an online source thinks. That is sound, and it is not what the code
 * did: `isConfidentEnoughToMove()` returned `true` for *all* music, and
 * MusicBrainz enrichment writes `artist` and `album`. So a file whose tags were
 * blank could be filed under an API guess, and so could a file sitting in the
 * review queue.
 *
 * The rule now: a music file may be filed when its own tags carry the artist
 * used to build its path, or when the match is `Exact`. API data alone never
 * moves a file, and nothing awaiting review moves at all.
 */
class MusicConfidenceGateTest extends TestCase
{
    use RefreshDatabase;

    private LibraryOrganizer $organizer;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organizer = app(LibraryOrganizer::class);
        $this->user = User::factory()->create();
    }

    public function test_a_file_with_its_own_artist_tag_is_still_filed(): void
    {
        // The case the exemption exists for, and the common one: the tags are
        // the file's own and describe it. This must keep working.
        $item = $this->music(artist: 'flipturn', confidence: MatchConfidence::Fuzzy, tagged: true);

        $this->assertTrue($this->organizer->canOrganize($item));
        $this->assertNotNull($this->organizer->organize($item));
    }

    public function test_a_guessed_artist_with_no_tag_behind_it_does_not_move_the_file(): void
    {
        // The bug: the artist came from an API text match, the file's own tags
        // said nothing, and the file was filed under the guess anyway.
        $item = $this->music(artist: 'flipturn', confidence: MatchConfidence::Fuzzy, tagged: false);

        $this->assertFalse($this->organizer->canOrganize($item));
        $this->assertNull($this->organizer->organize($item));
    }

    public function test_an_exact_match_files_even_without_a_tag(): void
    {
        // An exact match is an identifier match (an MBID or a resolving ISRC),
        // which is as good as the file's own tags.
        $item = $this->music(artist: 'flipturn', confidence: MatchConfidence::Exact, tagged: false);

        $this->assertTrue($this->organizer->canOrganize($item));
    }

    public function test_an_item_awaiting_review_is_not_filed(): void
    {
        // Moving a file the user has been asked to judge pre-empts the answer.
        $item = $this->music(artist: 'flipturn', confidence: MatchConfidence::Exact, tagged: true);
        $item->forceFill(['processing_status' => ProcessingStatus::NeedsReview])->saveQuietly();

        $this->assertFalse($this->organizer->canOrganize($item->fresh()));
    }

    public function test_an_item_the_user_already_judged_fine_is_filed(): void
    {
        // Reviewed means settled. A reviewed item must not be held back
        // forever — that is the S-302 trap in the other direction.
        $item = $this->music(artist: 'flipturn', confidence: MatchConfidence::Exact, tagged: true);
        $item->forceFill([
            'processing_status' => ProcessingStatus::NeedsReview,
            'reviewed_at' => now(),
        ])->saveQuietly();

        $this->assertTrue($this->organizer->canOrganize($item->fresh()));
    }

    /**
     * A music item with a file on the faked disk.
     *
     * `tagged` decides whether the file's *own* tags carried the artist,
     * recorded the way `FileTagger` records it.
     */
    private function music(string $artist, MatchConfidence $confidence, bool $tagged): MediaItem
    {
        $path = 'media/unsorted/track.mp3';
        Storage::disk('local')->put($path, 'audio');

        $item = MediaItem::create([
            'user_id' => $this->user->id,
            'type' => MediaItemType::Music,
            'title' => 'Chicago',
            'file_path' => Storage::disk('local')->path($path),
            'match_confidence' => $confidence,
            'owned' => true,
        ]);

        // Deliberately not fillable (it is set by the pipeline, never a form),
        // so it goes on the way the app sets it. The shape is FileTagger's:
        // whether the file's embedded tags named the artist, as opposed to a
        // filename guess or a later API match.
        $item->forceFill(['enrichment_report' => ['tagged_artist' => $tagged]])->saveQuietly();

        $item->musicMetadata()->create([
            'artist' => $artist,
            'album' => 'Heavy Colors',
            'track_number' => 3,
        ]);

        return $item->fresh();
    }
}
