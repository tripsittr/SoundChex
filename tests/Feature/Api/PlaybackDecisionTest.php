<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace Tests\Feature\Api;

use App\Enums\MediaItemType;
use App\Enums\ProcessingStatus;
use App\Jobs\TranscodeMediaJob;
use App\Models\MediaItem;
use App\Models\Profile;
use App\Models\User;
use App\Services\CurrentProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Telling a native client how to play something.
 *
 * The web player has asked this for a long time through `media.hls.decide`;
 * the apps could not, so they fetched the file and hoped. **An MKV handed to
 * AVPlayer is a black rectangle** — iOS cannot demux Matroska at all, whatever
 * codec is inside it — and nothing in the app could know that in advance.
 *
 * The same `StreamPolicy` decides for every client, so one rule governs them
 * all rather than the web and the apps drifting apart.
 */
class PlaybackDecisionTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $this->user = User::factory()->create();
    }

    /** A video item with a real file behind it. */
    private function film(string $name, string $contents = 'video bytes'): MediaItem
    {
        Storage::disk('local')->put("media/{$name}", $contents);

        return MediaItem::unresolved()->create([
            'user_id' => $this->user->id,
            'title' => pathinfo($name, PATHINFO_FILENAME),
            'type' => MediaItemType::Movie,
            'file_path' => Storage::disk('local')->path("media/{$name}"),
            'processing_status' => ProcessingStatus::Complete,
        ])->fresh();
    }

    public function test_it_requires_authentication(): void
    {
        $film = $this->film('a.mkv');

        $this->getJson("/api/v1/items/{$film->id}/playback")->assertUnauthorized();
    }

    /**
     * An MP4 of H.264 plays everywhere. Transcoding it would cost CPU and
     * quality for nothing.
     */
    public function test_a_playable_file_is_offered_directly(): void
    {
        $film = $this->film('a.mp4');

        $response = $this->actingAs($this->user)
            ->getJson("/api/v1/items/{$film->id}/playback")
            ->assertOk();

        $this->assertFalse($response->json('transcode'));
        $this->assertStringContainsString('/stream', (string) $response->json('url'));
        $this->assertTrue($response->json('direct_playable'));
    }

    /**
     * The case this exists for. Matroska is not playable on iOS whatever is
     * inside it, so the answer has to be HLS.
     */
    public function test_an_unplayable_container_is_offered_as_hls(): void
    {
        $film = $this->film('a.mkv');

        $response = $this->actingAs($this->user)
            ->getJson("/api/v1/items/{$film->id}/playback")
            ->assertOk();

        $this->assertTrue($response->json('transcode'));
        $this->assertStringContainsString('hls', (string) $response->json('url'));
    }

    /**
     * A client should not be offered a download that will not open. The app
     * asks this before showing one, so it can say "the original will not play
     * on this device" rather than storing a gigabyte that shows black.
     */
    public function test_an_unplayable_container_is_not_reported_as_directly_playable(): void
    {
        $film = $this->film('a.mkv');

        $this->actingAs($this->user)
            ->getJson("/api/v1/items/{$film->id}/playback")
            ->assertOk()
            ->assertJsonPath('direct_playable', false);
    }

    /* ------------------------------------------------- the lasting copy -- */

    /**
     * HLS makes it play now; the converted copy makes every later play direct
     * and makes an offline download possible at all.
     *
     * Nothing did this automatically before: conversion was a per-file button
     * in the admin table, so a library of MKVs stayed unplayable on a phone
     * until somebody clicked each one.
     */
    public function test_asking_to_play_an_unplayable_file_queues_a_copy(): void
    {
        Queue::fake();

        $film = $this->film('a.mkv');

        $this->actingAs($this->user)->getJson("/api/v1/items/{$film->id}/playback")->assertOk();

        Queue::assertPushed(TranscodeMediaJob::class);
    }

    public function test_a_playable_file_is_not_queued_for_conversion(): void
    {
        Queue::fake();

        $film = $this->film('a.mp4');

        $this->actingAs($this->user)->getJson("/api/v1/items/{$film->id}/playback")->assertOk();

        Queue::assertNothingPushed();
    }

    /**
     * Repeated plays of the same file must queue one job, not one per play —
     * otherwise opening a film three times transcodes it three times.
     */
    public function test_a_file_already_converted_is_not_queued_again(): void
    {
        Queue::fake();

        $film = $this->film('a.mkv');

        Storage::disk('local')->put('converted/a.mp4', 'converted bytes');

        $film->forceFill([
            'converted_path' => 'converted/a.mp4',
        ])->saveQuietly();

        $this->actingAs($this->user)->getJson("/api/v1/items/{$film->id}/playback")->assertOk();

        Queue::assertNothingPushed();
    }

    /* ----------------------------------------------- which file you get -- */

    /**
     * By default the converted copy, because it is the one that plays.
     */
    public function test_streaming_prefers_the_converted_copy(): void
    {
        $film = $this->film('a.mkv', 'the original');

        Storage::disk('local')->put('converted/a.mp4', 'the converted copy');
        $film->forceFill(['converted_path' => 'converted/a.mp4'])->saveQuietly();

        $response = $this->actingAs($this->user)->get("/api/v1/items/{$film->id}/stream");

        $response->assertOk();
        $this->assertSame('the converted copy', $response->streamedContent());
    }

    /**
     * `?variant=original` asks for the file as it sits on disk.
     *
     * Both are legitimate. A phone that cannot demux Matroska needs the MP4; a
     * desktop that can wants the original, with its full bitrate and its other
     * audio and subtitle tracks, since the converted copy is a single-track
     * reduction.
     */
    public function test_the_original_can_be_asked_for_by_name(): void
    {
        $film = $this->film('a.mkv', 'the original');

        Storage::disk('local')->put('converted/a.mp4', 'the converted copy');
        $film->forceFill(['converted_path' => 'converted/a.mp4'])->saveQuietly();

        $response = $this->actingAs($this->user)
            ->get("/api/v1/items/{$film->id}/stream?variant=original");

        $response->assertOk();
        $this->assertSame('the original', $response->streamedContent());
    }

    /**
     * A missing original falls back rather than 404ing: a file that has gone
     * is the converted copy's whole reason for existing.
     */
    public function test_a_missing_original_falls_back_to_the_copy(): void
    {
        $film = $this->film('a.mkv', 'the original');

        Storage::disk('local')->put('converted/a.mp4', 'the converted copy');
        $film->forceFill(['converted_path' => 'converted/a.mp4'])->saveQuietly();

        unlink((string) $film->absoluteFilePath());

        $response = $this->actingAs($this->user)
            ->get("/api/v1/items/{$film->id}/stream?variant=original");

        $response->assertOk();
        $this->assertSame('the converted copy', $response->streamedContent());
    }

    /**
     * The age gate applies, as it does on every other item route.
     *
     * `ContentGate` filters by **rating**, not ownership — the existing
     * `/stream` and `/details` endpoints both answer 200 to another account
     * for the same item, so this endpoint matching them is the correct
     * behaviour rather than a hole this introduced. (Whether a shared server
     * should scope items per account is a real question, but it is the same
     * question everywhere and not one to answer quietly here.)
     *
     * What must hold is that a restricted profile gets the same refusal from
     * this endpoint as from the ones beside it.
     */
    public function test_a_restricted_profile_is_refused(): void
    {
        $film = $this->film('a.mkv');

        $film->movieMetadata()->create(['mpaa_rating' => 'R']);

        $kid = Profile::create([
            'user_id' => $this->user->id,
            'name' => 'Kid',
            'max_rating' => 'PG',
        ]);

        $this->actingAs($this->user);
        app(CurrentProfile::class)->switchTo($kid->id);

        $this->getJson("/api/v1/items/{$film->id}/playback")->assertNotFound();
    }

    /**
     * And the same item is allowed once the rating is within reach, so the
     * test above is not passing because the endpoint refuses everything.
     */
    public function test_a_permitted_rating_is_allowed(): void
    {
        $film = $this->film('a.mkv');

        $film->movieMetadata()->create(['mpaa_rating' => 'PG']);

        $kid = Profile::create([
            'user_id' => $this->user->id,
            'name' => 'Kid',
            'max_rating' => 'PG',
        ]);

        $this->actingAs($this->user);
        app(CurrentProfile::class)->switchTo($kid->id);

        $this->getJson("/api/v1/items/{$film->id}/playback")->assertOk();
    }
}
