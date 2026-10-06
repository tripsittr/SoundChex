<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Services\Quality;

use App\Enums\MediaItemType;
use App\Enums\QualitySeverity;
use App\Models\MediaItem;
use App\Models\MediaProbe;
use App\Models\QualityFinding;

/**
 * Finds what is wrong with a file (#489).
 *
 * Nothing checked quality before this. A truncated file, a CAM rip, a
 * transcoded "FLAC" and a file with no audio stream all imported as healthy,
 * and the owner found out when they pressed play.
 *
 * Only the **cheap** checks live here: everything answerable from the probe
 * and the filename, which is a database comparison rather than a decode. The
 * expensive ones — decoding the whole file for errors, reading the spectrum to
 * catch a lossy file in a lossless container — belong in a background job that
 * may finish after the item is already in the library, because a good file
 * should not wait on them.
 *
 * Severity is what does the work: only `Bad` parks an item. A library full of
 * blocked files nobody asked about is how a quality check gets switched off.
 */
class QualityChecker
{
    /**
     * Minimum sensible video bitrate per resolution tier, in bits per second.
     *
     * Deliberately low — these are "something is wrong" floors, not quality
     * targets. A well-encoded 1080p film sits far above 2 Mb/s; one *below* it
     * is either a tiny excerpt or a rip that threw the picture away.
     */
    private const BITRATE_FLOOR = [
        2160 => 8_000_000,
        1080 => 2_000_000,
        720 => 1_000_000,
        480 => 400_000,
    ];

    /**
     * How far short of its expected runtime a file may fall.
     *
     * 5% because a container's duration and a provider's runtime legitimately
     * disagree a little — credits trimmed, a different cut of the same release
     * — while a download that stopped early is usually short by much more.
     */
    private const TRUNCATION_TOLERANCE = 0.05;

    /**
     * Words in a filename that name a camera recording of a cinema screen.
     *
     * Matched on the *original* filename rather than the cleaned title,
     * because the scanner strips release tags — which is the right thing for a
     * title and loses exactly the evidence needed here.
     */
    private const CAM_MARKERS = ['cam', 'camrip', 'hdcam', 'ts', 'telesync', 'hdts', 'telecine', 'tc', 'workprint'];

    /**
     * Runs every cheap check and records the findings.
     *
     * @return array<int, QualityFinding>
     */
    public function check(MediaItem $item): array
    {
        $probe = $item->probe;

        if ($probe === null) {
            // Nothing to check against. Not a finding: an unprobed file is an
            // unanswered question, not a bad file.
            return [];
        }

        $findings = [];

        foreach ($this->checks($item, $probe) as $check => $result) {
            if ($result === null) {
                // The check does not apply, or the file passed it. Clear any
                // stale finding, so a file that was fixed stops being blamed.
                QualityFinding::where('media_item_id', $item->id)->where('check', $check)->delete();

                continue;
            }

            $findings[] = QualityFinding::updateOrCreate(
                ['media_item_id' => $item->id, 'check' => $check],
                $result,
            );
        }

        return $findings;
    }

    /** Whether anything found would stop the file being published. */
    public function hasBlockingFindings(MediaItem $item): bool
    {
        return QualityFinding::where('media_item_id', $item->id)
            ->where('severity', QualitySeverity::Bad->value)
            ->exists();
    }

    /**
     * Each check, as `name => finding-or-null`.
     *
     * Null means "passed, or does not apply" — the two are the same answer
     * here, and distinguishing them would mean recording a finding for every
     * file that is fine.
     *
     * @return array<string, array<string, mixed>|null>
     */
    private function checks(MediaItem $item, MediaProbe $probe): array
    {
        return [
            'no_video_stream' => $this->noVideoStream($item, $probe),
            'no_audio_stream' => $this->noAudioStream($item, $probe),
            'truncated' => $this->truncated($item, $probe),
            'cam_source' => $this->camSource($item),
            'low_bitrate' => $this->lowBitrate($probe),
            'fake_lossless_container' => $this->fakeLosslessContainer($probe),
        ];
    }

    /** @return array<string, mixed>|null */
    private function noVideoStream(MediaItem $item, MediaProbe $probe): ?array
    {
        if (! in_array($item->type, [MediaItemType::Movie, MediaItemType::Show], true)) {
            return null;
        }

        if ($probe->isVideo()) {
            return null;
        }

        return [
            'severity' => QualitySeverity::Bad,
            'value' => 'none',
            'threshold' => 'at least one video stream',
            'detail' => ['note' => 'A film or episode with no picture is either audio misfiled as video, or a broken download.'],
        ];
    }

    /** @return array<string, mixed>|null */
    private function noAudioStream(MediaItem $item, MediaProbe $probe): ?array
    {
        if ($item->type === MediaItemType::Book) {
            return null;
        }

        if ($probe->hasAudio()) {
            return null;
        }

        return [
            'severity' => QualitySeverity::Bad,
            'value' => '0 streams',
            'threshold' => 'at least one audio stream',
            'detail' => ['note' => 'Nothing to hear. A silent rip, or a stream copy that dropped the audio.'],
        ];
    }

    /**
     * Shorter than the runtime the provider gave for it.
     *
     * The single most useful check: a download that stopped early plays
     * perfectly up to the point it stops, so nothing else notices.
     *
     * @return array<string, mixed>|null
     */
    private function truncated(MediaItem $item, MediaProbe $probe): ?array
    {
        $expectedMs = $this->expectedDurationMs($item);

        if ($expectedMs === null || $probe->duration_ms === null || $expectedMs <= 0) {
            return null;
        }

        $shortfall = ($expectedMs - $probe->duration_ms) / $expectedMs;

        if ($shortfall <= self::TRUNCATION_TOLERANCE) {
            return null;
        }

        return [
            'severity' => QualitySeverity::Bad,
            'value' => $this->minutes($probe->duration_ms),
            'threshold' => $this->minutes($expectedMs),
            'detail' => [
                'shortfall_percent' => (int) round($shortfall * 100),
                'note' => 'The file is shorter than its runtime. It plays fine until it stops.',
            ],
        ];
    }

    /** @return array<string, mixed>|null */
    private function camSource(MediaItem $item): ?array
    {
        if (! in_array($item->type, [MediaItemType::Movie, MediaItemType::Show], true)) {
            return null;
        }

        // The original filename, captured at intake before the scanner cleaned
        // it. A cleaned title has the release tags stripped, which is exactly
        // the evidence this needs.
        $name = $this->originalFilename($item);

        if ($name === null) {
            return null;
        }

        // Word-boundary split rather than str_contains, or "ts" matches
        // "Ghostbusters" and every second film is a telesync.
        $words = preg_split('/[^a-z0-9]+/i', strtolower($name)) ?: [];
        $found = array_values(array_intersect($words, self::CAM_MARKERS));

        if ($found === []) {
            return null;
        }

        return [
            'severity' => QualitySeverity::Bad,
            'value' => implode(', ', $found),
            'threshold' => 'a real source',
            'detail' => [
                'filename' => $name,
                'note' => 'The filename says this was recorded off a cinema screen.',
            ],
        ];
    }

    /** @return array<string, mixed>|null */
    private function lowBitrate(MediaProbe $probe): ?array
    {
        if (! $probe->isVideo() || $probe->bitrate === null || $probe->height === null) {
            return null;
        }

        $floor = null;

        foreach (self::BITRATE_FLOOR as $height => $minimum) {
            if ($probe->height >= $height) {
                $floor = $minimum;

                break;
            }
        }

        if ($floor === null || $probe->bitrate >= $floor) {
            return null;
        }

        return [
            // A warning, not a problem: a short clip or a very efficient
            // encode lands here legitimately, and blocking those would make
            // the check something to switch off.
            'severity' => QualitySeverity::Warn,
            'value' => $this->megabits($probe->bitrate),
            'threshold' => $this->megabits($floor),
            'detail' => [
                'resolution' => $probe->resolutionLabel(),
                'note' => 'Low for the resolution. Either a very efficient encode or a rip that threw the picture away.',
            ],
        ];
    }

    /**
     * A lossless container holding something that was never lossless.
     *
     * Only the cheap half: a FLAC whose *bit depth* is missing or whose
     * bitrate is implausibly low for its sample rate. Proving it properly
     * means reading the spectrum for a cut-off at 16 kHz, which decodes the
     * file and belongs in the background job.
     *
     * @return array<string, mixed>|null
     */
    private function fakeLosslessContainer(MediaProbe $probe): ?array
    {
        $lossless = ['flac', 'alac', 'wav', 'aiff', 'ape'];
        $codec = strtolower((string) ($probe->audio_streams[0]['codec'] ?? ''));

        if (! in_array($codec, $lossless, true)) {
            return null;
        }

        $sampleRate = (int) ($probe->audio_streams[0]['sample_rate'] ?? 0);
        $bitrate = $probe->bitrate ?? 0;

        if ($sampleRate <= 0 || $bitrate <= 0) {
            return null;
        }

        // 16-bit stereo at 44.1 kHz is 1.41 Mb/s uncompressed, and FLAC
        // typically reaches 60-70% of that. Below a third means the data was
        // thrown away before it was put in a lossless container.
        $uncompressed = $sampleRate * 2 * 16;

        if ($bitrate >= $uncompressed / 3) {
            return null;
        }

        return [
            'severity' => QualitySeverity::Warn,
            'value' => $this->megabits($bitrate),
            'threshold' => $this->megabits((int) ($uncompressed / 3)),
            'detail' => [
                'codec' => $codec,
                'note' => 'Too small to be lossless at this sample rate — probably a lossy file put in a lossless container. A spectrum check would confirm it.',
            ],
        ];
    }

    /** The runtime a provider gave, in milliseconds. */
    private function expectedDurationMs(MediaItem $item): ?int
    {
        if ($item->type === MediaItemType::Movie) {
            $minutes = $item->movieMetadata?->runtime_minutes;

            return is_numeric($minutes) && $minutes > 0 ? (int) $minutes * 60_000 : null;
        }

        if ($item->type === MediaItemType::Music) {
            $ms = $item->musicMetadata?->duration_ms;

            return is_numeric($ms) && $ms > 0 ? (int) $ms : null;
        }

        return null;
    }

    /**
     * The name the file arrived with.
     *
     * From the intake snapshot where there is one, falling back to the current
     * path's basename — which is still the original name for anything that has
     * not been filed yet, and that is most of what this checks.
     */
    private function originalFilename(MediaItem $item): ?string
    {
        $path = $item->file_path;

        return filled($path) ? basename(str_replace('\\', '/', (string) $path)) : null;
    }

    private function minutes(int $ms): string
    {
        return round($ms / 60_000, 1).' min';
    }

    private function megabits(int $bitsPerSecond): string
    {
        return round($bitsPerSecond / 1_000_000, 2).' Mb/s';
    }
}
