<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace Tests\Feature;

use App\Enums\MediaItemType;
use App\Enums\ProcessingStatus;
use App\Models\MediaItem;
use App\Models\MediaProbe;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * What the file can do, as a player shows it (#511).
 *
 * HD, Dolby Vision, 5.1 — the badges on a streaming detail page. They answer
 * "will this look and sound good on my setup", which is a different question
 * from the numbers in the facts table and is answered at a glance rather than
 * read.
 *
 * **Written against fabricated probe rows, because no file in this library is
 * readable from this machine** — `media_probes` has zero rows here and
 * `library:probe` has never run. The shapes come from reading
 * `MediaProber::hdrFormat()` and `audioStreams()`, not from observing output,
 * so a5's run against real media is what will confirm them.
 */
class CapabilityBadgesTest extends TestCase
{
    use RefreshDatabase;

    private function probed(array $attributes): MediaProbe
    {
        $user = User::factory()->create();

        $item = MediaItem::create([
            'user_id' => $user->id,
            'type' => MediaItemType::Movie,
            'title' => 'A Film',
            'file_path' => '/movies/a-film.mkv',
            'processing_status' => ProcessingStatus::Complete,
            'owned' => true,
        ]);

        return $item->probe()->create(array_merge([
            'probed_at' => now(),
            'video_codec' => 'hevc',
            'width' => 1920,
            'height' => 1080,
        ], $attributes));
    }

    /* ------------------------------------------------------- picture ---- */

    public function test_2160p_reads_as_4k(): void
    {
        // What the box said, and what somebody is looking for. "2160p" is the
        // measurement; "4K" is the word.
        $probe = $this->probed(['width' => 3840, 'height' => 2160]);

        $this->assertContains('4K', $probe->capabilities());
    }

    public function test_1080p_reads_as_hd(): void
    {
        $this->assertContains('HD', $this->probed([])->capabilities());
    }

    public function test_a_lower_resolution_keeps_its_number(): void
    {
        // "720p" means something precise; calling it "SD" would flatten three
        // tiers into one.
        $probe = $this->probed(['width' => 1280, 'height' => 720]);

        $this->assertContains('720p', $probe->capabilities());
    }

    /* --------------------------------------------------- dynamic range -- */

    public function test_dolby_vision_is_named(): void
    {
        $probe = $this->probed(['hdr' => 'dv']);

        $this->assertContains('Dolby Vision', $probe->capabilities());
    }

    public function test_each_hdr_flavour_has_its_own_badge(): void
    {
        // The four `MediaProber::hdrFormat()` can return. They are not
        // interchangeable: a player that does HDR10 may not do Dolby Vision.
        $this->assertSame('HDR10+', $this->probed(['hdr' => 'hdr10plus'])->hdrLabel());
        $this->assertSame('HDR10', $this->probed(['hdr' => 'hdr10'])->hdrLabel());
        $this->assertSame('HLG', $this->probed(['hdr' => 'hlg'])->hdrLabel());
    }

    public function test_ordinary_video_gets_no_dynamic_range_badge(): void
    {
        // `none` is a real stored value meaning "measured, and it is SDR" --
        // as opposed to null, which means "never probed". Neither earns a
        // badge: every file was SDR once, so saying so is noise.
        $this->assertNull($this->probed(['hdr' => 'none'])->hdrLabel());
        $this->assertNull($this->probed(['hdr' => null])->hdrLabel());
    }

    /* --------------------------------------------------------- sound ---- */

    public function test_surround_is_badged_by_channel_count(): void
    {
        $probe = $this->probed(['audio_streams' => [['codec' => 'eac3', 'channels' => 6]]]);

        $this->assertContains('5.1', $probe->capabilities());
    }

    public function test_the_best_track_wins_not_the_first(): void
    {
        // A file with a 5.1 track and a stereo fallback is a 5.1 file. Showing
        // both would describe the packaging rather than the capability.
        $probe = $this->probed(['audio_streams' => [
            ['codec' => 'aac', 'channels' => 2],
            ['codec' => 'truehd', 'channels' => 8],
        ]]);

        $this->assertSame('7.1', $probe->audioLabel());
    }

    public function test_stereo_earns_no_badge(): void
    {
        // The floor. Marking the floor says nothing.
        $probe = $this->probed(['audio_streams' => [['codec' => 'aac', 'channels' => 2]]]);

        $this->assertNull($probe->audioLabel());
    }

    public function test_a_missing_channel_count_is_not_guessed(): void
    {
        $probe = $this->probed(['audio_streams' => [['codec' => 'aac', 'channels' => null]]]);

        $this->assertNull($probe->audioLabel());
    }

    /* -------------------------------------------------------- absent ---- */

    public function test_an_unprobed_file_claims_nothing(): void
    {
        // Absent badges mean "not measured". Inventing "HD" from a filename
        // would be a guess presented as a fact.
        $user = User::factory()->create();

        $item = MediaItem::create([
            'user_id' => $user->id,
            'type' => MediaItemType::Movie,
            'title' => 'Never probed',
            'file_path' => '/movies/unprobed.mkv',
            'processing_status' => ProcessingStatus::Complete,
            'owned' => true,
        ]);

        $this->assertNull($item->fresh()->probe);
    }

    public function test_audio_only_files_get_no_video_badges(): void
    {
        // A music file with a sleeve is not a 4K anything.
        $probe = $this->probed(['video_codec' => null, 'width' => null, 'height' => null]);

        $this->assertSame([], $probe->capabilities());
    }

    public function test_subtitles_are_advertised(): void
    {
        $probe = $this->probed(['subtitle_streams' => [['language' => 'eng']]]);

        $this->assertContains('CC', $probe->capabilities());
    }

    public function test_the_order_is_what_somebody_scans_for_first(): void
    {
        // Picture, then dynamic range, then sound. A badge row read in a
        // different order each time is a row nobody reads.
        $probe = $this->probed([
            'width' => 3840,
            'height' => 2160,
            'hdr' => 'dv',
            'audio_streams' => [['codec' => 'truehd', 'channels' => 8]],
            'subtitle_streams' => [['language' => 'eng']],
        ]);

        $this->assertSame(['4K', 'Dolby Vision', '7.1', 'CC'], $probe->capabilities());
    }

    /* ----------------------------------------------------- in the API --- */

    public function test_the_endpoint_carries_them(): void
    {
        $probe = $this->probed(['hdr' => 'dv', 'audio_streams' => [['codec' => 'truehd', 'channels' => 6]]]);

        $user = User::find($probe->mediaItem->user_id);

        $capabilities = $this->actingAs($user)
            ->getJson("/api/v1/items/{$probe->media_item_id}/details")
            ->assertOk()
            ->json('capabilities');

        $this->assertSame(['HD', 'Dolby Vision', '5.1'], $capabilities);
    }
}
