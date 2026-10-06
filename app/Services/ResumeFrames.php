<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Services;

use App\Models\MediaItem;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

/**
 * The frame you stopped on, as the Continue Watching card (#513).
 *
 * A poster tells you which film it is. The frame you stopped on tells you
 * *where you are in it* — which is the only question that row is asking, and
 * the reason a shelf of identical posters is hard to scan.
 *
 * Measured on a real file before building this: seeking to ten minutes and
 * writing a 640px JPEG took **134ms**, producing a 26KB image. Cheap enough to
 * make on demand, which is why none of this is queued.
 */
class ResumeFrames
{
    /**
     * Where frames live, under the public disk so they are served as files
     * rather than through PHP.
     */
    private const DIRECTORY = 'resume-frames';

    /**
     * Positions are rounded to this before becoming a filename.
     *
     * Progress ticks every few seconds while something plays, and a frame per
     * tick would be hundreds of near-identical JPEGs for one viewing. Ten
     * seconds is the "within a few seconds" the request allows for, and it
     * means scrubbing back and forth reuses frames rather than making new ones.
     */
    private const ROUND_TO_SECONDS = 10;

    /** Wide enough for a card at 3x, small enough to be worth caching. */
    private const WIDTH = 640;

    /**
     * Long enough for a slow disk to seek, short enough that a stuck ffmpeg
     * cannot hold a web request open.
     */
    private const TIMEOUT_SECONDS = 15;

    /**
     * The frame for an item **only if it is already on disk**.
     *
     * Lets a caller serve every cached frame for nothing and then decide how
     * many it is willing to extract, which is what keeps a cold shelf from
     * running one ffmpeg per card inside a single request.
     */
    public function cachedUrlFor(MediaItem $item, int $positionSeconds, int $profileId): ?string
    {
        if ($positionSeconds < self::ROUND_TO_SECONDS) {
            return null;
        }

        $path = $this->pathFor($item, $positionSeconds, $profileId);

        return Storage::disk('public')->exists($path)
            ? Storage::disk('public')->url($path)
            : null;
    }

    /**
     * The URL of the frame for an item at a position, or null.
     *
     * Null rather than a placeholder: the caller falls back to the poster,
     * and a broken image is worse than an ordinary one. Reasons it can be null
     * are all legitimate — nothing watched yet, the file lives on another
     * machine, ffmpeg is absent, the format resists seeking.
     */
    public function urlFor(MediaItem $item, int $positionSeconds, int $profileId): ?string
    {
        if ($positionSeconds < self::ROUND_TO_SECONDS) {
            // Barely started, so the poster is still the better picture --
            // and a frame from the first seconds is usually a logo or black.
            return null;
        }

        $path = $this->pathFor($item, $positionSeconds, $profileId);

        if (Storage::disk('public')->exists($path)) {
            return Storage::disk('public')->url($path);
        }

        return $this->extract($item, $positionSeconds, $path)
            ? Storage::disk('public')->url($path)
            : null;
    }

    /**
     * Where one item's frame for one position and profile is kept.
     *
     * The profile is in the path because two people are at different points in
     * the same episode, and showing one person's frame to the other would be
     * both wrong and a small privacy leak.
     */
    private function pathFor(MediaItem $item, int $positionSeconds, int $profileId): string
    {
        $rounded = (int) (floor($positionSeconds / self::ROUND_TO_SECONDS) * self::ROUND_TO_SECONDS);

        return self::DIRECTORY."/{$profileId}/{$item->id}-{$rounded}.jpg";
    }

    /**
     * Pulls a single frame out of the file.
     *
     * `-ss` goes **before** `-i`, which is what makes this fast: input seeking
     * jumps to the nearest keyframe, where output seeking decodes from the
     * start. On the measured file that is 134ms against many seconds.
     */
    private function extract(MediaItem $item, int $positionSeconds, string $path): bool
    {
        $source = $item->absoluteFilePath();

        if ($source === null || ! is_file($source)) {
            // A library on another machine, which is normal rather than an
            // error -- the clients fall back to the poster.
            return false;
        }

        $ffmpeg = (string) config('transcode.ffmpeg');

        if ($ffmpeg === '') {
            return false;
        }

        $target = Storage::disk('public')->path($path);

        if (! is_dir(dirname($target))) {
            mkdir(dirname($target), 0775, true);
        }

        $process = new Process([
            $ffmpeg,
            // Never read stdin: without this ffmpeg can block waiting for a
            // keypress when it thinks it is attached to a terminal.
            '-nostdin',
            '-loglevel', 'error',
            '-ss', (string) $positionSeconds,
            '-i', $source,
            '-frames:v', '1',
            // Keeps the aspect ratio and forces an even height, which some
            // encoders require.
            '-vf', 'scale='.self::WIDTH.':-2',
            '-q:v', '4',
            '-y',
            $target,
        ]);

        $process->setTimeout(self::TIMEOUT_SECONDS);

        try {
            $process->run();
        } catch (ProcessTimedOutException) {
            Log::warning('Extracting a resume frame timed out', [
                'item' => $item->id,
                'position' => $positionSeconds,
            ]);

            return false;
        }

        if (! $process->isSuccessful() || ! is_file($target) || filesize($target) === 0) {
            // Logged rather than thrown: a missing thumbnail must never stop
            // the home page rendering.
            Log::warning('Could not extract a resume frame', [
                'item' => $item->id,
                'position' => $positionSeconds,
                'error' => mb_substr(trim($process->getErrorOutput()), 0, 300),
            ]);

            // A zero-byte file would be served as a broken image for ever.
            if (is_file($target)) {
                @unlink($target);
            }

            return false;
        }

        return true;
    }

    /**
     * Removes an item's frames, for one profile or for all of them.
     *
     * Called when something is finished or removed: the shelf no longer shows
     * it, so the images are dead weight. Without this the cache grows by one
     * JPEG per item per ten seconds watched and nothing ever reclaims it.
     *
     * A profile is passed when one person finishes something, because the
     * other people sharing the server are still part-way through it and their
     * frames must survive. Omitting it means the item itself is going away.
     */
    public function forget(MediaItem $item, ?int $profileId = null): int
    {
        $disk = Storage::disk('public');
        $removed = 0;

        $directories = $profileId !== null
            ? [self::DIRECTORY.'/'.$profileId]
            : $disk->directories(self::DIRECTORY);

        foreach ($directories as $directory) {
            foreach ($disk->files($directory) as $file) {
                // The prefix is matched with the separator attached, so item
                // 12 does not take item 1234's frames with it.
                if (str_starts_with(basename($file), $item->id.'-')) {
                    $disk->delete($file);
                    $removed++;
                }
            }
        }

        return $removed;
    }
}
