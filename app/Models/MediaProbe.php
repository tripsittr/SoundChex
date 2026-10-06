<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What ffprobe says a file actually contains (#489).
 *
 * Before this, no video technical data was stored anywhere -- which is why
 * keep-best for video compared file sizes, letting a 10% larger file win
 * whatever it held. A 1080p remux and a 4K rip were indistinguishable to the
 * code deciding which to keep.
 */
class MediaProbe extends Model
{
    protected $fillable = [
        'media_item_id', 'probed_at', 'container', 'duration_ms', 'bitrate',
        'size_bytes', 'video_codec', 'width', 'height', 'fps', 'hdr',
        'bit_depth', 'audio_streams', 'subtitle_streams', 'raw',
    ];

    protected $casts = [
        'probed_at' => 'datetime',
        'duration_ms' => 'integer',
        'bitrate' => 'integer',
        'size_bytes' => 'integer',
        'width' => 'integer',
        'height' => 'integer',
        'fps' => 'float',
        'bit_depth' => 'integer',
        'audio_streams' => 'array',
        'subtitle_streams' => 'array',
        'raw' => 'array',
    ];

    public function mediaItem(): BelongsTo
    {
        return $this->belongsTo(MediaItem::class);
    }

    /** Whether this is a video file, as opposed to audio with a sleeve. */
    public function isVideo(): bool
    {
        return $this->video_codec !== null && $this->height !== null;
    }

    /**
     * The vertical resolution tier, as people talk about it.
     *
     * Rounded to the nearest standard rather than reported exactly, because
     * 1920x1038 and 1920x1080 are both "1080p" to anyone choosing between
     * copies -- a cropped aspect ratio is not a lower quality tier.
     */
    public function resolutionLabel(): ?string
    {
        return match (true) {
            $this->height === null => null,
            $this->height >= 2000 => '2160p',
            $this->height >= 1000 => '1080p',
            $this->height >= 700 => '720p',
            $this->height >= 550 => '576p',
            $this->height >= 400 => '480p',
            default => $this->height.'p',
        };
    }

    /**
     * What the file can do, as the short labels a player shows.
     *
     * The badges on a streaming service's detail page -- HD, Dolby Vision,
     * 5.1 -- answer "will this look and sound good on my setup", which is a
     * different question from the numbers in the facts table and is answered
     * at a glance rather than read.
     *
     * Derived from the probe rather than stored, so a re-probe corrects them
     * and nothing can drift out of step with the file.
     *
     * Ordered by what someone scans for first: picture, then dynamic range,
     * then sound. Empty for an unprobed file, which is the honest answer --
     * absent badges mean "not measured", and inventing "HD" from a filename
     * would be a guess presented as a fact.
     *
     * @return array<int, string>
     */
    public function capabilities(): array
    {
        if (! $this->isVideo()) {
            return [];
        }

        $badges = [];

        if ($label = $this->resolutionLabel()) {
            // "4K" rather than "2160p": it is what the box said, and what
            // somebody is looking for.
            $badges[] = $label === '2160p' ? '4K' : ($label === '1080p' ? 'HD' : $label);
        }

        if ($hdr = $this->hdrLabel()) {
            $badges[] = $hdr;
        }

        if ($audio = $this->audioLabel()) {
            $badges[] = $audio;
        }

        if (($this->subtitle_streams ?? []) !== []) {
            $badges[] = 'CC';
        }

        return $badges;
    }

    /**
     * The dynamic-range badge, or null for ordinary video.
     *
     * `none` is a real stored value meaning "measured, and it is SDR" -- as
     * opposed to null, which means "never probed". Neither earns a badge:
     * every file was SDR once, so saying so is noise.
     */
    public function hdrLabel(): ?string
    {
        return match ($this->hdr) {
            'dv' => 'Dolby Vision',
            'hdr10plus' => 'HDR10+',
            'hdr10' => 'HDR10',
            'hlg' => 'HLG',
            default => null,
        };
    }

    /**
     * The best audio the file offers, as a channel count.
     *
     * The *best* rather than a list: a file with a 5.1 track and a stereo
     * fallback is a 5.1 file, and showing both would describe the packaging
     * rather than the capability.
     *
     * Stereo earns no badge for the same reason SDR does not -- it is the
     * floor, and marking the floor says nothing.
     */
    /**
     * Video codecs a browser or AVPlayer will decode.
     *
     * **H.264 only.** Everything else is a gamble on the device, the OS
     * version and sometimes the hardware.
     *
     * VP8, VP9 and Theora were on this list and should not have been:
     * AVFoundation does not decode any of them, so a `.webm` of VP9 passed
     * both the container and the codec check and went to direct play --
     * which is the precise failure this whole check exists to prevent.
     * Safari on the desktop plays VP9 and the phone does not, and the phone
     * is where it matters.
     *
     * HEVC plays on recent Apple hardware and almost nowhere else, which
     * makes it exactly the "sometimes" that produces a black rectangle on
     * the one device somebody happens to be holding.
     */
    private const PLAYABLE_VIDEO = ['h264', 'avc1'];

    /**
     * Audio codecs that will come out of a speaker.
     *
     * The reason this list exists: nothing checked audio at all, so an `.mp4`
     * carrying AC-3 or DTS passed as playable on its extension and then
     * played **silently** — a file that looks like it works and does not,
     * which is worse than one that plainly fails.
     *
     * Opus and Vorbis are deliberately absent. Opus decodes on Apple only
     * inside a CAF container, not in the mp4 or WebM a library actually
     * holds, and Vorbis not at all — so listing them would have sent those
     * files to direct play and produced silence, the same way AC-3 did.
     */
    private const PLAYABLE_AUDIO = ['aac', 'mp3', 'flac', 'alac'];

    /**
     * Whether this file plays as-is, judged on what is actually inside it.
     *
     * The extension is not the question. A `.mp4` is a container, and one
     * holding HEVC video or AC-3 audio is as unplayable as an MKV — it simply
     * fails later and less obviously, because the container opened fine.
     *
     * Null when nothing has been probed: "unknown" is not "fine", and the
     * caller decides what to do with an unmeasured file rather than being
     * told it is safe.
     */
    public function playsDirectly(): ?bool
    {
        if (! $this->isVideo()) {
            return null;
        }

        $video = strtolower((string) $this->video_codec);

        if ($video === '') {
            return null;
        }

        if (! in_array($video, self::PLAYABLE_VIDEO, true)) {
            return false;
        }

        // 10-bit H.264 is **High 10**, which Apple's hardware decoder refuses
        // outright -- the audio plays and the picture stays black.
        //
        // The codec name alone does not catch this: the stream is genuinely
        // `h264` in a genuinely `.mp4`, so every other check passes and the
        // file direct-plays into a black rectangle. The depth was already
        // measured and simply never consulted.
        //
        // This is the same failure #305 fixed on the *transcode* side, by the
        // other route: there, libx264 inherited 10-bit from the source and
        // emitted High 10; here, a 10-bit file is handed over untouched.
        if (($this->bit_depth ?? 8) > 8) {
            return false;
        }

        $audio = collect($this->audio_streams ?? [])
            ->pluck('codec')
            ->filter()
            ->map(fn ($codec): string => strtolower((string) $codec));

        // A file with no audio track is fine -- silent by design rather than
        // silent by accident.
        if ($audio->isEmpty()) {
            return true;
        }

        // **Any** playable track is enough: the transcoder maps one audio
        // stream, and a player picks a track it can decode. A rip carrying
        // AC-3 alongside AAC is playable through the AAC.
        return $audio->contains(fn (string $codec): bool => in_array($codec, self::PLAYABLE_AUDIO, true));
    }

    public function audioLabel(): ?string
    {
        $channels = collect($this->audio_streams ?? [])
            ->pluck('channels')
            ->filter(fn ($value): bool => is_numeric($value))
            ->map(fn ($value): int => (int) $value)
            ->max();

        return match (true) {
            $channels === null || $channels <= 2 => null,
            $channels >= 8 => '7.1',
            $channels >= 6 => '5.1',
            default => $channels.'.0',
        };
    }

    /** Whether any audio stream exists at all. */
    public function hasAudio(): bool
    {
        return ($this->audio_streams ?? []) !== [];
    }

    /** @return array<int, string> The languages audio is available in. */
    public function audioLanguages(): array
    {
        return collect($this->audio_streams ?? [])
            ->pluck('language')
            ->filter()
            ->unique()
            ->values()
            ->all();
    }
}
