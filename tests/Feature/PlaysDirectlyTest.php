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
 * Whether a file plays as-is, judged on what is inside it.
 *
 * `isPlayableVideo()` asked only the **extension**, so a `.mp4` carrying HEVC
 * video or AC-3 audio passed as playable, was sent to direct play, and died on
 * the device. A container is not a codec: an `.mp4` of HEVC is as unplayable
 * as an MKV, it just fails later and less obviously because the container
 * opened fine.
 *
 * The audio half matters on its own. Nothing checked audio codecs anywhere, so
 * an AC-3 or DTS track played **silently** — a file that looks like it works
 * and does not, which is worse than one that plainly fails.
 */
class PlaysDirectlyTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
    }

    /**
     * @param  array<int, array<string, mixed>>  $audio
     */
    private function film(string $extension, ?string $video, array $audio = []): MediaItem
    {
        $item = MediaItem::unresolved()->create([
            'user_id' => $this->user->id,
            'title' => 'A Film',
            'type' => MediaItemType::Movie,
            'file_path' => "films/a.{$extension}",
            'processing_status' => ProcessingStatus::Complete,
        ]);

        if ($video !== null) {
            MediaProbe::create([
                'media_item_id' => $item->id,
                'probed_at' => now(),
                'video_codec' => $video,
                'audio_streams' => $audio,
                'width' => 1920,
                'height' => 1080,
            ]);
        }

        return $item->fresh()->load('probe');
    }

    /* ---------------------------------------------------------- video --- */

    public function test_h264_in_mp4_plays_directly(): void
    {
        $film = $this->film('mp4', 'h264', [['codec' => 'aac', 'channels' => 2]]);

        $this->assertTrue($film->isPlayableVideo());
    }

    /**
     * The case that was wrong. The extension says mp4 and the codec says no.
     */
    public function test_hevc_in_mp4_does_not_play_directly(): void
    {
        $film = $this->film('mp4', 'hevc', [['codec' => 'aac', 'channels' => 2]]);

        $this->assertFalse(
            $film->isPlayableVideo(),
            'An .mp4 of HEVC is as unplayable as an MKV; the container opening is not the question.',
        );
    }

    public function test_h264_in_mkv_does_not_play_directly(): void
    {
        $film = $this->film('mkv', 'h264', [['codec' => 'aac', 'channels' => 2]]);

        // The codec is fine; iOS cannot demux Matroska whatever is inside it.
        $this->assertFalse($film->isPlayableVideo());
    }

    /* ---------------------------------------------------------- audio --- */

    /**
     * Nothing checked audio before, so this file was served direct and played
     * with no sound at all.
     */
    public function test_ac3_audio_does_not_play_directly(): void
    {
        $film = $this->film('mp4', 'h264', [['codec' => 'ac3', 'channels' => 6]]);

        $this->assertFalse(
            $film->isPlayableVideo(),
            'AC-3 plays silently rather than failing, which is worse than failing.',
        );
    }

    public function test_dts_audio_does_not_play_directly(): void
    {
        $film = $this->film('mp4', 'h264', [['codec' => 'dts', 'channels' => 6]]);

        $this->assertFalse($film->isPlayableVideo());
    }

    /**
     * A rip carrying AC-3 **and** AAC is playable through the AAC: the
     * transcoder maps one stream and a player picks one it can decode.
     * Transcoding this would be CPU spent for nothing.
     */
    public function test_one_playable_track_among_several_is_enough(): void
    {
        $film = $this->film('mp4', 'h264', [
            ['codec' => 'ac3', 'channels' => 6],
            ['codec' => 'aac', 'channels' => 2],
        ]);

        $this->assertTrue($film->isPlayableVideo());
    }

    /**
     * Silent by design is not the same as silent by accident.
     */
    public function test_a_file_with_no_audio_still_plays(): void
    {
        $film = $this->film('mp4', 'h264', []);

        $this->assertTrue($film->isPlayableVideo());
    }

    /* -------------------------------------------------- the unmeasured --- */

    /**
     * An unprobed file is the only case still judged on its extension —
     * "unknown" must not silently become "unplayable", or every file would
     * transcode until the probe caught up.
     */
    public function test_an_unprobed_file_falls_back_to_the_extension(): void
    {
        $mp4 = $this->film('mp4', null);
        $mkv = $this->film('mkv', null);

        $this->assertTrue($mp4->isPlayableVideo());
        $this->assertFalse($mkv->isPlayableVideo());
    }

    /**
     * The probe is only consulted when it is already loaded: this is called
     * once per row on listings, and a lazy read would be a query each.
     */
    public function test_an_unloaded_probe_does_not_cost_a_query(): void
    {
        $film = $this->film('mp4', 'hevc', [['codec' => 'aac']]);

        // Fetched fresh, deliberately without the relation.
        $unloaded = MediaItem::withoutGlobalScopes()->findOrFail($film->id);

        $this->assertFalse($unloaded->relationLoaded('probe'));

        // Falls back to the extension rather than lazily loading. That is the
        // trade: listings stay cheap, and the endpoints that decide playback
        // load the relation explicitly.
        $this->assertTrue($unloaded->isPlayableVideo());
    }

    /* ------------------------------------------------- through the API --- */

    /**
     * The decision endpoint must load the probe, or the codec check it exists
     * for never runs.
     */
    public function test_the_playback_endpoint_transcodes_hevc_in_mp4(): void
    {
        $film = $this->film('mp4', 'hevc', [['codec' => 'aac', 'channels' => 2]]);

        $this->actingAs($this->user)
            ->getJson("/api/v1/items/{$film->id}/playback")
            ->assertOk()
            ->assertJsonPath('transcode', true);
    }

    public function test_the_playback_endpoint_direct_plays_h264_in_mp4(): void
    {
        $film = $this->film('mp4', 'h264', [['codec' => 'aac', 'channels' => 2]]);

        $this->actingAs($this->user)
            ->getJson("/api/v1/items/{$film->id}/playback")
            ->assertOk()
            ->assertJsonPath('transcode', false);
    }
}
