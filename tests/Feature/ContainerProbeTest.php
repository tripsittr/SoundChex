<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace Tests\Feature;

use App\Services\ContainerProbe;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

/**
 * Reading what is in a container rather than trusting its extension.
 *
 * Three tracks from a Spotify export were catalogued as films because they had
 * been written to `.mp4`, which is configured as a film extension. The
 * extension was a guess and the guess was wrong.
 *
 * The degraded path matters as much as the working one: ffprobe is optional in
 * this project, and a probe that returned "no video" when it simply could not
 * ask would recategorise a library the first time it went missing.
 */
class ContainerProbeTest extends TestCase
{
    private string $file;

    protected function setUp(): void
    {
        parent::setUp();

        // A real file, because the probe refuses paths that are not there —
        // its contents never matter, the faked ffprobe decides.
        $this->file = tempnam(sys_get_temp_dir(), 'soundchex-probe-') . '.mp4';
        file_put_contents($this->file, 'not really an mp4');
    }

    protected function tearDown(): void
    {
        @unlink($this->file);

        parent::tearDown();
    }

    public function test_a_file_with_a_video_stream_has_video(): void
    {
        $this->fakeStreams([
            ['codec_type' => 'video', 'codec_name' => 'h264'],
            ['codec_type' => 'audio', 'codec_name' => 'aac'],
        ]);

        $this->assertTrue((new ContainerProbe)->hasVideo($this->file));
    }

    public function test_an_audio_only_file_does_not(): void
    {
        // The three that ended up in the film list.
        $this->fakeStreams([['codec_type' => 'audio', 'codec_name' => 'aac']]);

        $this->assertFalse((new ContainerProbe)->hasVideo($this->file));
    }

    public function test_embedded_cover_art_is_not_a_film(): void
    {
        // A sleeve is a video stream by codec_type. Counting it would leave
        // every tagged track looking like a film, which is worse than the bug.
        $this->fakeStreams([
            ['codec_type' => 'audio', 'codec_name' => 'aac'],
            ['codec_type' => 'video', 'codec_name' => 'mjpeg', 'disposition' => ['attached_pic' => 1]],
        ]);

        $this->assertFalse((new ContainerProbe)->hasVideo($this->file));
    }

    public function test_it_says_it_does_not_know_when_ffprobe_fails(): void
    {
        Process::fake([
            '*' => Process::result(output: '', errorOutput: 'not found', exitCode: 1),
        ]);

        $this->assertNull(
            (new ContainerProbe)->hasVideo($this->file),
            'A failed probe must not be reported as "no video".',
        );
    }

    public function test_it_says_it_does_not_know_when_the_output_is_not_json(): void
    {
        Process::fake(['*' => Process::result(output: 'ffprobe version 8.1.1')]);

        $this->assertNull((new ContainerProbe)->hasVideo($this->file));
    }

    public function test_it_says_it_does_not_know_about_a_file_that_is_not_there(): void
    {
        Process::fake();

        $this->assertNull((new ContainerProbe)->hasVideo($this->file . '-gone'));

        // And does not spend a process launch finding out.
        Process::assertNothingRan();
    }

    public function test_only_containers_that_carry_either_are_worth_asking_about(): void
    {
        $probe = new ContainerProbe;

        foreach (['film.mp4', 'film.mkv', 'clip.webm', 'clip.MOV'] as $path) {
            $this->assertTrue($probe->isAmbiguous($path), "{$path} should be probed");
        }

        // An audio-only .avi is rare enough not to be worth a process launch
        // per file across a library of thousands.
        foreach (['film.avi', 'film.mpg', 'song.mp3', 'book.epub'] as $path) {
            $this->assertFalse($probe->isAmbiguous($path), "{$path} should not be probed");
        }
    }

    /** @param array<int, array<string, mixed>> $streams */
    private function fakeStreams(array $streams): void
    {
        Process::fake(['*' => Process::result(output: json_encode(['streams' => $streams]))]);
    }
}
