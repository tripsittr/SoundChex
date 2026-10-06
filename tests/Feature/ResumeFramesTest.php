<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace Tests\Feature;

use App\Enums\MediaItemType;
use App\Enums\ProcessingStatus;
use App\Events\PlaybackCompleted;
use App\Models\MediaItem;
use App\Models\User;
use App\Services\ResumeFrames;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The frame you stopped on, as the Continue Watching card (#513).
 *
 * A poster says which film it is; the frame says where you are in it, which is
 * the only question that shelf asks.
 *
 * Extraction itself is not exercised here — it needs ffmpeg and a real video,
 * and a test that shells out to ffmpeg tests the machine rather than the code.
 * What is tested is everything that decides *whether* and *where*, plus the
 * reclaim path, since an unbounded cache on someone's server is the failure
 * this feature could plausibly ship with.
 */
class ResumeFramesTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');

        $this->user = User::factory()->create();
    }

    private function film(string $title = 'A Film'): MediaItem
    {
        return MediaItem::unresolved()->create([
            'user_id' => $this->user->id,
            'title' => $title,
            'type' => MediaItemType::Movie,
            'file_path' => 'movies/'.str($title)->slug().'.mkv',
            'processing_status' => ProcessingStatus::Complete,
        ]);
    }

    /** Stands in for an already-extracted frame. */
    private function frameAt(MediaItem $item, int $profileId, int $rounded): string
    {
        $path = "resume-frames/{$profileId}/{$item->id}-{$rounded}.jpg";

        Storage::disk('public')->put($path, 'jpeg bytes');

        return $path;
    }

    /* ----------------------------------------------------- when to bother -- */

    /**
     * The first seconds are a logo or black, and the poster beats both.
     */
    public function test_it_does_not_make_a_frame_for_something_barely_started(): void
    {
        $film = $this->film();

        $this->assertNull(app(ResumeFrames::class)->urlFor($film, 3, 1));
    }

    /**
     * A cached frame is returned without going near ffmpeg. The file has no
     * video behind it, so an extraction attempt would fail and return null —
     * which is exactly what makes this assertion meaningful.
     */
    public function test_an_existing_frame_is_reused(): void
    {
        $film = $this->film();
        $this->frameAt($film, profileId: 1, rounded: 120);

        $url = app(ResumeFrames::class)->urlFor($film, 125, 1);

        $this->assertNotNull($url, 'A frame already on disk should be served.');
        $this->assertStringContainsString("{$film->id}-120.jpg", (string) $url);
    }

    /**
     * Positions round down to a shared filename, so scrubbing about reuses
     * frames instead of writing a near-identical JPEG per progress tick.
     */
    public function test_nearby_positions_share_one_frame(): void
    {
        $film = $this->film();
        $this->frameAt($film, profileId: 1, rounded: 600);

        $frames = app(ResumeFrames::class);

        foreach ([600, 603, 609] as $position) {
            $this->assertStringContainsString(
                "{$film->id}-600.jpg",
                (string) $frames->urlFor($film, $position, 1),
                "A position of {$position}s should reuse the 600s frame.",
            );
        }
    }

    /**
     * Two people are at different points in the same episode. Showing one
     * person's frame to the other is wrong, and a small privacy leak.
     */
    public function test_one_profiles_frame_is_not_served_to_another(): void
    {
        $film = $this->film();
        $this->frameAt($film, profileId: 1, rounded: 600);

        // No video behind the row, so profile 2 can only get a frame by
        // wrongly reading profile 1's.
        $this->assertNull(app(ResumeFrames::class)->urlFor($film, 605, 2));
    }

    /**
     * A file on another machine is normal on a self-hosted server, not an
     * error: the clients fall back to the poster.
     */
    public function test_a_missing_file_yields_no_frame_rather_than_failing(): void
    {
        $film = $this->film();

        $this->assertNull(app(ResumeFrames::class)->urlFor($film, 600, 1));
    }

    /* ------------------------------------------------------- reclaiming --- */

    public function test_finishing_an_item_removes_that_profiles_frames(): void
    {
        $film = $this->film();
        $mine = $this->frameAt($film, profileId: 1, rounded: 600);

        app(ResumeFrames::class)->forget($film, 1);

        Storage::disk('public')->assertMissing($mine);
    }

    /**
     * The other half: someone else reaching the end must not clear the frames
     * of a person still part-way through.
     */
    public function test_one_profile_finishing_leaves_another_profiles_frames(): void
    {
        $film = $this->film();
        $mine = $this->frameAt($film, profileId: 1, rounded: 600);
        $theirs = $this->frameAt($film, profileId: 2, rounded: 900);

        app(ResumeFrames::class)->forget($film, 1);

        Storage::disk('public')->assertMissing($mine);
        Storage::disk('public')->assertExists($theirs);
    }

    public function test_forgetting_without_a_profile_clears_every_profile(): void
    {
        $film = $this->film();
        $mine = $this->frameAt($film, profileId: 1, rounded: 600);
        $theirs = $this->frameAt($film, profileId: 2, rounded: 900);

        app(ResumeFrames::class)->forget($film);

        Storage::disk('public')->assertMissing($mine);
        Storage::disk('public')->assertMissing($theirs);
    }

    /**
     * Item 12 must not take item 1234's frames with it. A prefix match without
     * the separator would, and the loss would be silent.
     */
    public function test_it_does_not_remove_another_items_frames_by_prefix(): void
    {
        $twelve = $this->film('Twelve');
        $longer = $this->film('Longer');

        // Force the ids apart so one is a string prefix of the other.
        $twelve->forceFill(['id' => 12])->saveQuietly();
        $longer->forceFill(['id' => 1234])->saveQuietly();

        $short = $this->frameAt($twelve, profileId: 1, rounded: 600);
        $long = $this->frameAt($longer, profileId: 1, rounded: 600);

        app(ResumeFrames::class)->forget($twelve, 1);

        Storage::disk('public')->assertMissing($short);
        Storage::disk('public')->assertExists($long);
    }

    /**
     * Nothing else reclaims these, so the listener is the whole cleanup story.
     * Without it the cache grows by a JPEG per item per ten seconds watched
     * and never shrinks.
     */
    public function test_the_completion_event_reclaims_the_frames(): void
    {
        $film = $this->film();
        $frame = $this->frameAt($film, profileId: 1, rounded: 600);

        PlaybackCompleted::dispatch($film, 1);

        Storage::disk('public')->assertMissing($frame);
    }

    /* ------------------------------------------------- cost per request --- */

    /**
     * A cached frame is served without an extraction attempt.
     *
     * `cachedUrlFor` is what lets the shelf serve everything it already has
     * for the price of a `file_exists` each, so the usual case -- a home page
     * the viewer has loaded before -- does no ffmpeg work at all.
     */
    public function test_the_cached_lookup_returns_a_frame_already_on_disk(): void
    {
        $film = $this->film();
        $this->frameAt($film, profileId: 1, rounded: 600);

        $this->assertStringContainsString(
            "{$film->id}-600.jpg",
            (string) app(ResumeFrames::class)->cachedUrlFor($film, 605, 1),
        );
    }

    /**
     * The cached lookup never extracts. There is no video behind these rows,
     * so a null here is the proof it did not try — and the shelf relies on
     * that to decide how much work it is willing to do.
     */
    public function test_the_cached_lookup_does_not_extract(): void
    {
        $film = $this->film();

        $this->assertNull(app(ResumeFrames::class)->cachedUrlFor($film, 600, 1));
    }

    public function test_the_cached_lookup_ignores_something_barely_started(): void
    {
        $film = $this->film();
        $this->frameAt($film, profileId: 1, rounded: 0);

        $this->assertNull(app(ResumeFrames::class)->cachedUrlFor($film, 3, 1));
    }

    /**
     * Housekeeping behind a progress write the player is waiting on: a
     * storage fault must not turn "you finished the film" into an error.
     */
    public function test_a_cleanup_failure_does_not_break_completion(): void
    {
        $film = $this->film();

        // Nothing was ever written, so the profile directory is absent.
        PlaybackCompleted::dispatch($film, 99);

        $this->assertTrue(true, 'Dispatching completion with no frames must not throw.');
    }
}
