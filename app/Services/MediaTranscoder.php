<?php

namespace App\Services;

use App\Enums\MediaItemType;
use App\Models\MediaItem;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

/**
 * Produces browser-playable copies of media the browser can't decode.
 *
 * Browsers handle a narrow set of containers and codecs — an MKV holding HEVC
 * plays in none of them. Rather than showing a black rectangle, this writes an
 * H.264/AAC MP4 alongside the original.
 *
 * The original is never touched. Conversion is lossy and the source is the
 * user's own copy, so it stays exactly where it is.
 */
class MediaTranscoder
{
    public function isAvailable(): bool
    {
        return Process::run([config('transcode.ffmpeg', 'ffmpeg'), '-version'])->successful();
    }

    /**
     * Whether converting this item would actually gain anything.
     */
    public function needsConversion(MediaItem $item): bool
    {
        if (! $item->hasReadableFile()) {
            return false;
        }

        // A converted copy already exists.
        if (filled($item->converted_path) && $this->convertedFileExists($item)) {
            return false;
        }

        return match ($item->type) {
            MediaItemType::Movie, MediaItemType::Show => ! $item->isPlayableVideo()
                || $this->hasUnplayableCodec($item),
            default => false,
        };
    }

    /**
     * Inspects the file's actual streams.
     *
     * The extension is a poor guide: an .mp4 can carry HEVC, which Chrome and
     * Firefox refuse, so the codec has to be read rather than assumed.
     *
     * @return array{container: string, video: ?string, audio: ?string, height: ?int, duration: ?float}
     */
    public function probe(MediaItem $item): array
    {
        $path = $item->absoluteFilePath();

        $empty = ['container' => '', 'video' => null, 'audio' => null, 'height' => null, 'duration' => null];

        if ($path === null) {
            return $empty;
        }

        $result = Process::timeout(60)->run([
            config('transcode.ffprobe', 'ffprobe'),
            '-v', 'error',
            '-print_format', 'json',
            '-show_format',
            '-show_streams',
            $path,
        ]);

        if (! $result->successful()) {
            return $empty;
        }

        $data = json_decode($result->output(), true) ?? [];

        $video = collect($data['streams'] ?? [])->firstWhere('codec_type', 'video');
        $audio = collect($data['streams'] ?? [])->firstWhere('codec_type', 'audio');

        return [
            'container' => strtolower(pathinfo($path, PATHINFO_EXTENSION)),
            'video' => $video['codec_name'] ?? null,
            'audio' => $audio['codec_name'] ?? null,
            'height' => isset($video['height']) ? (int) $video['height'] : null,
            'duration' => isset($data['format']['duration']) ? (float) $data['format']['duration'] : null,
        ];
    }

    /**
     * Converts to H.264/AAC MP4.
     *
     * @param  callable|null  $onProgress  Receives 0–100 as encoding advances.
     * @return string|null Relative path to the converted file, or null on failure.
     */
    public function convert(MediaItem $item, ?callable $onProgress = null): ?string
    {
        $source = $item->absoluteFilePath();

        if ($source === null || ! $this->isAvailable()) {
            return null;
        }

        $probe = $this->probe($item);
        $target = $this->targetPath($item);
        $absoluteTarget = Storage::path($target);

        $directory = dirname($absoluteTarget);

        if (! is_dir($directory) && ! mkdir($directory, 0775, true) && ! is_dir($directory)) {
            return null;
        }

        // Encode to a temporary name so a failed or interrupted run never
        // leaves a half-written file that looks complete.
        //
        // The .mp4 extension has to be preserved: ffmpeg infers the output
        // container from it, and a bare ".part" suffix makes it refuse to open
        // the file at all ("Invalid argument").
        $working = preg_replace('/\.mp4$/', '.part.mp4', $absoluteTarget) ?? $absoluteTarget;

        $result = Process::timeout((int) config('transcode.timeout_seconds', 21600))
            ->run(
                $this->buildCommand($source, $working, $probe),
                function (string $type, string $line) use ($onProgress, $probe): void {
                    if ($onProgress !== null) {
                        $this->reportProgress($line, $probe['duration'] ?? null, $onProgress);
                    }
                },
            );

        if (! $result->successful() || ! file_exists($working)) {
            @unlink($working);

            return null;
        }

        rename($working, $absoluteTarget);

        return $target;
    }

    /**
     * @param array{video: ?string, height: ?int} $probe
     * @return array<int, string>
     */
    private function buildCommand(string $source, string $target, array $probe): array
    {
        $video = (array) config('transcode.video');
        $audio = (array) config('transcode.audio');

        $command = [
            config('transcode.ffmpeg', 'ffmpeg'),
            '-y',
            '-i', $source,
            // Take only the first video and audio stream: many rips carry a
            // dozen audio tracks and subtitle streams MP4 can't hold.
            '-map', '0:v:0',
            '-map', '0:a:0?',
        ];

        $command = array_merge($command, $this->videoArguments($video, $probe));

        $command = array_merge($command, [
            '-c:a', $audio['codec'] ?? 'aac',
            '-b:a', $audio['bitrate'] ?? '192k',
            '-ac', (string) ($audio['channels'] ?? 2),
            // Lets playback start before the whole file downloads.
            '-movflags', '+faststart',
            // Progress lines are parsed from stderr; this keeps them terse.
            '-stats_period', '5',
            $target,
        ]);

        return $command;
    }

    /**
     * @param array<string, mixed> $video
     * @param array{height: ?int} $probe
     * @return array<int, string>
     */
    private function videoArguments(array $video, array $probe): array
    {
        $encoder = $this->resolveEncoder((string) ($video['encoder'] ?? 'auto'));

        $arguments = ['-c:v', $encoder];

        if ($encoder === 'h264_videotoolbox') {
            // Hardware encoding targets a bitrate rather than a quality level.
            $arguments = array_merge($arguments, ['-b:v', (string) ($video['hardware_bitrate'] ?? '6M')]);
        } else {
            $arguments = array_merge($arguments, [
                '-preset', (string) ($video['preset'] ?? 'medium'),
                '-crf', (string) ($video['crf'] ?? 21),
            ]);
        }

        // yuv420p is the only pixel format with universal browser support;
        // 10-bit sources otherwise produce a file nothing will play.
        $arguments = array_merge($arguments, ['-pix_fmt', 'yuv420p']);

        $maxHeight = (int) ($video['max_height'] ?? 1080);

        if (($probe['height'] ?? 0) > $maxHeight) {
            // -2 keeps the width even, which H.264 requires.
            $arguments = array_merge($arguments, ['-vf', "scale=-2:{$maxHeight}"]);
        }

        return $arguments;
    }

    /**
     * Picks the hardware encoder when it's actually present.
     */
    private function resolveEncoder(string $configured): string
    {
        if ($configured !== 'auto') {
            return $configured;
        }

        static $hardware = null;

        if ($hardware === null) {
            $result = Process::run([config('transcode.ffmpeg', 'ffmpeg'), '-hide_banner', '-encoders']);
            $hardware = $result->successful() && str_contains($result->output(), 'h264_videotoolbox');
        }

        return $hardware ? 'h264_videotoolbox' : 'libx264';
    }

    /**
     * Turns an ffmpeg status line into a percentage.
     *
     * ffmpeg reports elapsed output time rather than a percentage, so progress
     * is that against the probed duration.
     */
    private function reportProgress(string $line, ?float $duration, callable $onProgress): void
    {
        if ($duration === null || $duration <= 0) {
            return;
        }

        if (! preg_match('/time=(\d+):(\d+):(\d+)/', $line, $match)) {
            return;
        }

        $seconds = ((int) $match[1] * 3600) + ((int) $match[2] * 60) + (int) $match[3];

        $onProgress(min(100, (int) round(($seconds / $duration) * 100)));
    }

    private function targetPath(MediaItem $item): string
    {
        $root = trim((string) config('transcode.output_root', 'media/converted'), '/');

        // Keyed by id so two films sharing a title can't overwrite each other.
        $name = preg_replace('/[^\w\- ]+/u', '', $item->title) ?: 'item';

        return $root . '/' . $item->id . '-' . trim($name) . '.mp4';
    }

    private function convertedFileExists(MediaItem $item): bool
    {
        return filled($item->converted_path)
            && file_exists(Storage::path($item->converted_path));
    }

    /**
     * Codecs no mainstream browser decodes, regardless of container.
     */
    private function hasUnplayableCodec(MediaItem $item): bool
    {
        $probe = $this->probe($item);

        return in_array($probe['video'], ['hevc', 'h265', 'vp9_2', 'av1', 'mpeg2video', 'wmv3', 'vc1'], true);
    }
}
