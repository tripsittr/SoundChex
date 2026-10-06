<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace Tests\Feature;

use App\Enums\MediaItemType;
use App\Enums\ProcessingStatus;
use App\Models\MediaItem;
use App\Models\MediaPlay;
use App\Models\User;
use App\Services\CurrentProfile;
use App\Services\MediaBrowser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * "Continue watching" over the API (S-414).
 *
 * The row that makes a home page feel like the person's own rather than a
 * catalogue. The web has had it for a long time; the apps could not build it
 * themselves because resume position lives in `media_plays` and the library
 * mirror does not carry it.
 */
class ContinueWatchingApiTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
    }

    private function film(string $title): MediaItem
    {
        return MediaItem::unresolved()->create([
            'user_id' => $this->user->id,
            'title' => $title,
            'type' => MediaItemType::Movie,
            'file_path' => 'movies/'.str($title)->slug().'.mkv',
            'processing_status' => ProcessingStatus::Complete,
        ]);
    }

    private function play(
        MediaItem $item,
        ?int $seconds,
        bool $completed = false,
        ?string $at = null,
    ): void {
        $play = MediaPlay::create([
            'media_item_id' => $item->id,
            'user_id' => $this->user->id,
            // The browser scopes to the *profile* whenever there is one, and
            // `CurrentProfile` creates a default on first ask -- so a row with
            // only a user id is invisible to it. Tests that call the browser
            // directly have to say which profile watched.
            'profile_id' => app(CurrentProfile::class)->id(),
            'position_seconds' => $seconds,
            'completed' => $completed,
        ]);

        if ($at !== null) {
            // Written after the fact: `updated_at` is managed, so an explicit
            // one has to be forced past the timestamp handling for these
            // tests to control which session counts as the latest.
            $play->forceFill(['updated_at' => $at])->saveQuietly();
        }
    }

    public function test_it_returns_a_part_watched_film(): void
    {
        $started = $this->film('Half Watched');
        $this->play($started, seconds: 1800);

        $response = $this->actingAs($this->user)->getJson('/api/v1/library/continue');

        $response->assertOk();

        $ids = collect($response->json('watching'))->pluck('id');

        $this->assertTrue($ids->contains($started->id));
    }

    public function test_a_finished_film_is_not_offered_again(): void
    {
        $done = $this->film('Finished');
        $this->play($done, seconds: 7000, completed: true);

        $response = $this->actingAs($this->user)->getJson('/api/v1/library/continue');

        $this->assertFalse(
            collect($response->json('watching'))->pluck('id')->contains($done->id),
        );
    }

    /**
     * A few seconds in is a mis-tap, not a viewing. Offering it back as
     * "continue" is how a home page fills with things nobody watched.
     */
    public function test_a_film_barely_started_is_not_offered(): void
    {
        $glanced = $this->film('Ten Seconds In');
        $this->play($glanced, seconds: 10);

        $response = $this->actingAs($this->user)->getJson('/api/v1/library/continue');

        $this->assertFalse(
            collect($response->json('watching'))->pluck('id')->contains($glanced->id),
        );
    }

    public function test_an_unwatched_film_is_not_offered(): void
    {
        $never = $this->film('Never Opened');

        $response = $this->actingAs($this->user)->getJson('/api/v1/library/continue');

        $this->assertFalse(
            collect($response->json('watching'))->pluck('id')->contains($never->id),
        );
    }

    /**
     * Someone else's viewing is not yours. Two people share a login and each
     * has their own profile; a shared home page showing the other person's
     * half-finished films is a small privacy failure as well as a useless row.
     */
    public function test_another_users_progress_does_not_leak(): void
    {
        $theirs = $this->film('Their Film');

        $other = User::factory()->create();

        MediaPlay::create([
            'media_item_id' => $theirs->id,
            'user_id' => $other->id,
            'position_seconds' => 1800,
            'completed' => false,
        ]);

        $response = $this->actingAs($this->user)->getJson('/api/v1/library/continue');

        $this->assertFalse(
            collect($response->json('watching'))->pluck('id')->contains($theirs->id),
        );
    }

    /* ------------------------------------------- which position is shown -- */

    /**
     * Several viewings of one film are one card, not several.
     *
     * Selecting the position alongside the item is what broke this: the row
     * was kept unique by `distinct()`, which only holds while every selected
     * column matches, so a second play row made the same film appear twice --
     * observed in the real library at 240s and 111s.
     */
    public function test_a_film_watched_twice_appears_once(): void
    {
        $film = $this->film('Twice Started');
        $this->play($film, seconds: 111, at: '2026-09-13 18:45:25');
        $this->play($film, seconds: 240, at: '2026-09-23 02:12:00');

        $response = $this->actingAs($this->user)->getJson('/api/v1/library/continue');

        $response->assertOk();

        $ids = collect($response->json('watching'))->pluck('id');

        $this->assertSame(
            1,
            $ids->filter(fn ($id) => $id === $film->id)->count(),
            'One film with two play rows must be one card.',
        );
    }

    /**
     * The frame shown is where the viewer *is*, not the furthest they ever got.
     *
     * Restarting a film abandoned near the end puts the latest session at a
     * couple of minutes while the furthest point reached is still near the
     * end, so reading the maximum would show a frame from the finale of
     * something just begun, spoiling it.
     *
     * An earlier attempt used `MAX(position_seconds)` with a `GROUP BY`;
     * against it this test reads 5400 and fails, which is the point.
     *
     * The second assertion then pins the shape rather than the value: no
     * aggregate over the position at all, so which row is reported stays the
     * query's decision and not the engine's. It reads the SQL from the
     * connection, because `toQuery()` on the returned collection rebuilds a
     * key lookup carrying none of the original select -- an assertion against
     * that passes for any implementation, as a first version of this test did.
     */
    public function test_restarting_a_film_shows_the_new_position_not_the_furthest(): void
    {
        $film = $this->film('Restarted');
        $this->play($film, seconds: 5400, at: '2026-09-01 20:00:00');
        $this->play($film, seconds: 120, at: '2026-10-01 20:00:00');

        $this->actingAs($this->user)->getJson('/api/v1/library/continue')->assertOk();

        $position = app(MediaBrowser::class)
            ->continueWatching()
            ->firstWhere('id', $film->id)
            ?->resume_position;

        $this->assertSame(
            120,
            (int) $position,
            'The latest session is at 120s; 5400 is where a previous viewing reached.',
        );

        // The engine-independent half: the query must not compute the
        // position with an aggregate, because then which row it reports is
        // the engine's choice rather than the query's.
        //
        // Captured from the connection, not from `toQuery()` on the result:
        // that rebuilds a key lookup from the models and carries none of the
        // original select, so it would pass against any implementation.
        $statements = [];

        DB::listen(function ($query) use (&$statements) {
            $statements[] = $query->sql;
        });

        app(MediaBrowser::class)->continueWatching();

        $selects = array_values(array_filter(
            $statements,
            fn (string $sql) => str_contains($sql, 'resume_position'),
        ));

        $this->assertNotEmpty($selects, 'The shelf query should select a resume position.');

        foreach ($selects as $sql) {
            $this->assertStringNotContainsStringIgnoringCase(
                'max(',
                $sql,
                'The position must come from the latest session, not an aggregate '
                .'whose result depends on the database engine.',
            );
        }
    }

    /**
     * A play row with no position is skipped rather than taken as the latest.
     *
     * `position_seconds` is nullable and genuinely null in the real library --
     * item 7196 has eleven rows and the three most recent carry no position.
     * Reading the newest row regardless would yield null and lose the frame
     * for an item with a good position one row down.
     */
    public function test_a_positionless_row_does_not_hide_a_real_position(): void
    {
        $film = $this->film('Positionless Latest');
        $this->play($film, seconds: 900, at: '2026-09-20 10:00:00');
        $this->play($film, seconds: null, at: '2026-09-21 10:00:00');

        // Through a request first: the browser scopes to the signed-in
        // viewer, so calling it with nobody authenticated returns nothing at
        // all and the assertion below would pass for the wrong reason.
        $response = $this->actingAs($this->user)->getJson('/api/v1/library/continue');

        $response->assertOk();

        $this->assertTrue(
            collect($response->json('watching'))->pluck('id')->contains($film->id),
            'A film whose newest play row has no position must still be on the shelf.',
        );

        $position = app(MediaBrowser::class)
            ->continueWatching()
            ->firstWhere('id', $film->id)
            ?->resume_position;

        $this->assertSame(
            900,
            (int) $position,
            'The newest row has no position, so the 900s session is the one to use.',
        );
    }

    /* ----------------------------------------------------- resume frames -- */

    /**
     * The shelf carries the frame each viewer stopped on (#513).
     *
     * Sent beside the items rather than replacing `artwork`, so a client that
     * has not been updated still renders posters, and one that has can fall
     * back when a frame is absent.
     */
    public function test_the_shelf_carries_a_frames_map(): void
    {
        $response = $this->actingAs($this->user)->getJson('/api/v1/library/continue');

        $response->assertOk();
        $response->assertJsonStructure(['watching', 'reading', 'resume_frames']);
    }

    /**
     * An already-extracted frame is offered for the item it belongs to.
     */
    public function test_an_existing_frame_is_offered_for_its_item(): void
    {
        Storage::fake('public');

        $film = $this->film('Has A Frame');

        // Signed in *before* the profile is asked for: `CurrentProfile`
        // resolves against the authenticated user, so reading the id first
        // gives a different profile from the one the request will use, and the
        // frame gets filed under a directory nothing looks in.
        $this->actingAs($this->user);

        $profileId = app(CurrentProfile::class)->id();

        $this->play($film, seconds: 605);

        Storage::disk('public')
            ->put("resume-frames/{$profileId}/{$film->id}-600.jpg", 'jpeg bytes');

        $response = $this->getJson('/api/v1/library/continue');

        $response->assertOk();

        $this->assertStringContainsString(
            "{$film->id}-600.jpg",
            (string) $response->json('resume_frames.'.$film->id),
        );
    }

    /**
     * A film with no frame is simply absent from the map, not null or a
     * placeholder: the client falls back to the poster, and a broken image is
     * worse than an ordinary one.
     */
    public function test_an_item_without_a_frame_is_absent_rather_than_null(): void
    {
        Storage::fake('public');

        $film = $this->film('No Frame');
        $this->play($film, seconds: 900);

        $response = $this->actingAs($this->user)->getJson('/api/v1/library/continue');

        $response->assertOk();

        // There is no video behind the row, so nothing can be extracted.
        $this->assertArrayNotHasKey(
            (string) $film->id,
            (array) $response->json('resume_frames'),
        );
    }

    public function test_it_requires_authentication(): void
    {
        $this->getJson('/api/v1/library/continue')->assertUnauthorized();
    }

    public function test_it_returns_both_shelves(): void
    {
        $response = $this->actingAs($this->user)->getJson('/api/v1/library/continue');

        $response->assertOk();
        $response->assertJsonStructure(['watching', 'reading']);
    }
}
