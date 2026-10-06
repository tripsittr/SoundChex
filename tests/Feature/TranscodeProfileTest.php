<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace Tests\Feature;

use App\Enums\MediaItemType;
use App\Enums\ProcessingStatus;
use App\Models\MediaItem;
use App\Models\User;
use App\Services\Streaming\HlsSegmenter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use ReflectionMethod;
use Tests\TestCase;

/**
 * The transcode has to emit a profile Apple will decode.
 *
 * Reported twice as "audio plays, no video" — which reads as a broken stream
 * and is actually an unsupported **profile**. libx264 inherits the source's
 * pixel format, so a 10-bit rip (common for anime, and for anything encoded
 * from a good master) came out as **High 10**. Apple's hardware decoder
 * refuses High 10 outright.
 *
 * The file transcoder already knew this — its own comment says "10-bit sources
 * otherwise produce a file nothing will play" — and the HLS path never learned
 * it. That is why this is asserted rather than left to a comment.
 *
 * These run ffmpeg for real where it is available: the bug is in what the
 * encoder *produces*, and an argument-list assertion alone would have passed
 * while the output was still unplayable.
 */
class TranscodeProfileTest extends TestCase
{
    use RefreshDatabase;

    private function ffmpeg(): ?string
    {
        $binary = (string) config('transcode.ffmpeg', 'ffmpeg');

        return Process::run(['which', $binary])->successful()
            || is_executable($binary) ? $binary : null;
    }

    /* ------------------------------------------------- the arguments --- */

    public function test_the_hls_command_pins_the_profile_and_pixel_format(): void
    {
        $user = User::factory()->create();

        $item = MediaItem::unresolved()->create([
            'user_id' => $user->id,
            'title' => 'A Film',
            'type' => MediaItemType::Movie,
            'file_path' => '/nonexistent/a.mkv',
            'processing_status' => ProcessingStatus::Complete,
        ]);

        $method = new ReflectionMethod(HlsSegmenter::class, 'command');
        $command = $method->invoke(app(HlsSegmenter::class), '/nonexistent/a.mkv', '/tmp/x', 1080, 0.0);

        $this->assertContains('-pix_fmt', $command);
        $this->assertContains('yuv420p', $command);
        $this->assertContains('-profile:v', $command);
        $this->assertContains('high', $command);

        // Level matters too: without a ceiling a large source can produce a
        // level older hardware will not decode.
        $this->assertContains('-level:v', $command);
    }

    /* ---------------------------------------------------- the output --- */

    /**
     * The assertion that would have caught this. A 10-bit source through the
     * real command must come out 8-bit High, not High 10.
     */
    public function test_a_ten_bit_source_transcodes_to_an_apple_playable_profile(): void
    {
        $ffmpeg = $this->ffmpeg();

        if ($ffmpeg === null) {
            $this->markTestSkipped('No ffmpeg on this machine.');
        }

        $directory = sys_get_temp_dir().'/sc-profile-'.bin2hex(random_bytes(4));
        @mkdir($directory, 0775, true);

        $source = $directory.'/source.mkv';

        // A genuinely 10-bit file, which is what produced High 10.
        $made = Process::timeout(120)->run([
            $ffmpeg, '-hide_banner', '-loglevel', 'error',
            '-f', 'lavfi', '-i', 'testsrc=duration=1:size=320x240:rate=10',
            '-pix_fmt', 'yuv420p10le', '-c:v', 'libx264', '-y', $source,
        ]);

        if (! $made->successful() || ! is_file($source)) {
            $this->markTestSkipped('This ffmpeg cannot encode 10-bit H.264.');
        }

        // Exactly the command the server runs.
        $method = new ReflectionMethod(HlsSegmenter::class, 'command');
        $command = $method->invoke(app(HlsSegmenter::class), $source, $directory, 1080, 0.0);

        Process::timeout(180)->run($command);

        $segment = $directory.'/seg000.ts';

        $this->assertFileExists($segment, 'The transcode produced no segment.');

        $probe = Process::run([
            str_replace('ffmpeg', 'ffprobe', $ffmpeg),
            '-v', 'error', '-select_streams', 'v:0',
            '-show_entries', 'stream=profile,pix_fmt',
            '-of', 'default=nw=1', $segment,
        ])->output();

        $this->assertStringNotContainsString(
            'High 10',
            $probe,
            'High 10 is refused by Apple hardware decoders: the audio plays and the picture stays black.',
        );

        $this->assertStringContainsString('yuv420p', $probe);
        $this->assertStringNotContainsString('yuv420p10le', $probe);

        // Tidy up the encode.
        foreach ((array) glob($directory.'/*') as $file) {
            @unlink((string) $file);
        }

        @rmdir($directory);
    }
}
