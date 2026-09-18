<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace Tests\Feature;

use Tests\TestCase;

/**
 * The transcode config resolves ffmpeg/ffprobe in a defined order (S-151
 * Step 8): an explicit FFMPEG_PATH/FFPROBE_PATH wins; otherwise a binary bundled
 * beside the PHP binary is used; otherwise the bare name (found on PATH).
 */
class TranscodeBinaryResolutionTest extends TestCase
{
    public function test_explicit_env_path_wins(): void
    {
        // The config reads env at load; set it and re-read the file directly.
        putenv('FFMPEG_PATH=/opt/custom/ffmpeg');
        putenv('FFPROBE_PATH=/opt/custom/ffprobe');

        $config = require base_path('config/transcode.php');

        $this->assertSame('/opt/custom/ffmpeg', $config['ffmpeg']);
        $this->assertSame('/opt/custom/ffprobe', $config['ffprobe']);

        putenv('FFMPEG_PATH');
        putenv('FFPROBE_PATH');
    }

    public function test_falls_back_to_a_resolvable_name_without_env(): void
    {
        putenv('FFMPEG_PATH');
        putenv('FFPROBE_PATH');

        $config = require base_path('config/transcode.php');

        // With no env and (in the test env) no bundled binary beside PHP_BINARY,
        // it must fall back to a non-empty name — either the bare 'ffmpeg' or a
        // discovered bundled path. Never empty, which would break Process::run.
        $this->assertNotEmpty($config['ffmpeg']);
        $this->assertNotEmpty($config['ffprobe']);
        $this->assertStringContainsString('ffmpeg', $config['ffmpeg']);
        $this->assertStringContainsString('ffprobe', $config['ffprobe']);
    }
}
