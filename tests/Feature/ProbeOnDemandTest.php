<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace Tests\Feature;

use App\Enums\MediaItemType;
use App\Enums\ProcessingStatus;
use App\Models\MediaItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

/**
 * A file nobody has measured is measured before deciding how to play it.
 *
 * a5's review of #304: judging an unprobed file on its container alone is
 * optimistic in the one direction that hurts — an `.mp4` of HEVC is called
 * playable, direct-plays, and shows a black rectangle. The hourly backfill
 * closes that window eventually, but "eventually" is the whole viewing
 * somebody is trying to start now.
 *
 * ffprobe reads a header rather than a file, so this is cheap on the path,
 * and it happens once: the row is written and every later decision reads it.
 */
class ProbeOnDemandTest extends TestCase
{
    use RefreshDatabase;

    private function ffmpeg(): ?string
    {
        $binary = (string) config('transcode.ffmpeg', 'ffmpeg');

        return Process::run(['which', $binary])->successful() ? $binary : null;
    }

    /** A real file, because the point is that it gets measured. */
    private function film(string $name, array $encode): ?MediaItem
    {
        $ffmpeg = $this->ffmpeg();

        if ($ffmpeg === null) {
            return null;
        }

        $directory = sys_get_temp_dir().'/sc-ondemand-'.bin2hex(random_bytes(4));
        @mkdir($directory, 0775, true);

        $path = $directory.'/'.$name;

        $made = Process::timeout(120)->run(array_merge(
            [$ffmpeg, '-hide_banner', '-loglevel', 'error',
                '-f', 'lavfi', '-i', 'testsrc=duration=1:size=320x240:rate=10'],
            $encode,
            ['-y', $path],
        ));

        if (! $made->successful() || ! is_file($path)) {
            return null;
        }

        return MediaItem::unresolved()->create([
            'user_id' => User::factory()->create()->id,
            'title' => $name,
            'type' => MediaItemType::Movie,
            'file_path' => $path,
            'processing_status' => ProcessingStatus::Complete,
        ])->fresh();
    }

    /**
     * The window a5 identified. Before this, an unprobed HEVC `.mp4` was
     * called playable and sent to direct play.
     */
    public function test_an_unprobed_file_is_measured_before_the_decision(): void
    {
        $film = $this->film('hevc.mp4', ['-c:v', 'libx265', '-tag:v', 'hvc1', '-an']);

        if ($film === null) {
            $this->markTestSkipped('No ffmpeg, or this build cannot encode HEVC.');
        }

        $this->assertNull($film->probe, 'The fixture must start unmeasured.');

        $user = User::find($film->user_id);

        $response = $this->actingAs($user)
            ->getJson("/api/v1/items/{$film->id}/playback")
            ->assertOk();

        // Measured on the way through.
        $this->assertNotNull(
            $film->fresh()->probe,
            'The decision path should have probed a file nobody had measured.',
        );

        // And the decision reflects the codec, not the .mp4 extension.
        $this->assertTrue(
            $response->json('transcode'),
            'HEVC must not be direct-played just because the container is mp4.',
        );

        @unlink((string) $film->file_path);
        @rmdir(dirname((string) $film->file_path));
    }

    /**
     * The other half: an H.264 file is measured too, and still direct-plays.
     * A probe-on-demand that routed everything to HLS would be worse than the
     * bug it replaced.
     */
    public function test_measuring_does_not_make_a_playable_file_transcode(): void
    {
        $film = $this->film('h264.mp4', ['-c:v', 'libx264', '-pix_fmt', 'yuv420p', '-an']);

        if ($film === null) {
            $this->markTestSkipped('No ffmpeg on this machine.');
        }

        $user = User::find($film->user_id);

        $response = $this->actingAs($user)
            ->getJson("/api/v1/items/{$film->id}/playback")
            ->assertOk();

        $this->assertNotNull($film->fresh()->probe);
        $this->assertFalse(
            $response->json('transcode'),
            'An H.264 mp4 should still direct-play once measured.',
        );

        @unlink((string) $film->file_path);
        @rmdir(dirname((string) $film->file_path));
    }

    /**
     * A file that cannot be measured — gone, or on another machine — must
     * still answer rather than failing the request.
     */
    public function test_an_unmeasurable_file_still_returns_a_decision(): void
    {
        $user = User::factory()->create();

        $film = MediaItem::unresolved()->create([
            'user_id' => $user->id,
            'title' => 'Absent',
            'type' => MediaItemType::Movie,
            'file_path' => '/nonexistent/absent.mp4',
            'processing_status' => ProcessingStatus::Complete,
        ]);

        $this->actingAs($user)
            ->getJson("/api/v1/items/{$film->id}/playback")
            ->assertOk()
            ->assertJsonStructure(['transcode', 'url']);
    }
}
