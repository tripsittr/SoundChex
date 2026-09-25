<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Services\Streaming;

/**
 * Why a track or film is being served the way it is (S-29).
 *
 * Carried rather than returned as a bare boolean so the reason survives to the
 * logs and the admin panel. "Why is this transcoding?" is the question this
 * feature will actually generate, and a boolean cannot answer it.
 */
final readonly class PlaybackDecision
{
    private function __construct(
        public bool $transcode,
        public string $reason,
        public ?int $maxHeight = null,
    ) {}

    public static function direct(string $reason): self
    {
        return new self(false, $reason);
    }

    public static function transcode(string $reason, ?int $maxHeight = null): self
    {
        return new self(true, $reason, $maxHeight);
    }

    /** @return array{transcode: bool, reason: string, max_height: ?int} */
    public function toArray(): array
    {
        return [
            'transcode' => $this->transcode,
            'reason' => $this->reason,
            'max_height' => $this->maxHeight,
        ];
    }
}
