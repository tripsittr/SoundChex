<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Services;

use Illuminate\Support\Facades\Process;

/**
 * What is actually inside a container, when its extension will not say.
 *
 * `.mp4` is configured as a film extension and `.mp3` as music, which is right
 * until something exports audio to `.mp4` — and plenty does. Three tracks from
 * a Spotify export were catalogued as films on that basis alone, sitting in the
 * film list with names like "Lights Out - Royal Blood".
 *
 * The extension is a guess. The streams are the answer.
 */
class ContainerProbe
{
    /**
     * Containers that carry either, so are worth the cost of asking.
     *
     * `.mkv` and `.mp4` hold audio-only files routinely. `.avi` and `.mpg` are
     * old enough that audio-only ones are vanishingly rare, and every probe
     * costs a process launch during a scan of thousands of files.
     */
    private const AMBIGUOUS = ['mp4', 'm4v', 'mkv', 'webm', 'mov'];

    public function isAmbiguous(string $path): bool
    {
        return in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), self::AMBIGUOUS, true);
    }

    /**
     * Whether the file carries a video stream.
     *
     * Null when it cannot be determined — ffprobe absent, unreadable file,
     * output that does not parse. Null is not "no": a caller that treated it
     * as one would recategorise a library the first time ffprobe went missing,
     * which is precisely the kind of quiet damage this project's external
     * binaries are meant never to cause.
     */
    public function hasVideo(string $path): ?bool
    {
        if (! is_file($path)) {
            return null;
        }

        try {
            $result = Process::timeout(30)->run([
                config('transcode.ffprobe', 'ffprobe'),
                '-v', 'error',
                '-print_format', 'json',
                '-show_streams',
                $path,
            ]);
        } catch (\Throwable $e) {
            return null;
        }

        if (! $result->successful()) {
            return null;
        }

        $data = json_decode($result->output(), true);

        if (! is_array($data) || ! isset($data['streams']) || ! is_array($data['streams'])) {
            return null;
        }

        foreach ($data['streams'] as $stream) {
            if (($stream['codec_type'] ?? null) === 'video') {
                // Cover art is a video stream by codec_type, and a track with
                // an embedded sleeve is not a film. Attached pictures say so.
                if ((int) ($stream['disposition']['attached_pic'] ?? 0) === 1) {
                    continue;
                }

                return true;
            }
        }

        return false;
    }
}
