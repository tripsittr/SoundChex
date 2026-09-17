<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace Tests\Unit;

use App\Models\MediaItem;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Telling an absolute path from a relative one.
 *
 * The test was a leading separator, which is correct on Unix and wrong on
 * Windows for every path there is: "C:\Users\..." does not start with one, so
 * it would be treated as relative, prefixed with the storage root, and found
 * unreadable. Not one file — every file in the library, at once.
 */
class AbsolutePathTest extends TestCase
{
    #[DataProvider('paths')]
    public function test_it_recognises_a_path(string $path, bool $absolute): void
    {
        $item = new MediaItem();

        $method = new \ReflectionMethod($item, 'isAbsolutePath');

        $this->assertSame($absolute, $method->invoke($item, $path), $path);
    }

    public static function paths(): array
    {
        return [
            'unix absolute' => ['/Users/someone/Music/track.mp3', true],
            'unix relative' => ['media/library/Music/track.mp3', false],

            // The case the old test missed entirely.
            'windows drive' => ['C:\\Users\\someone\\Music\\track.mp3', true],
            'windows drive forward slash' => ['C:/Users/someone/Music/track.mp3', true],
            'windows lowercase drive' => ['d:\\Media\\track.mp3', true],

            // A library on a NAS, which is a likely way to hold 35 GB.
            'unc share' => ['\\\\nas\\media\\track.mp3', true],

            'windows relative' => ['media\\library\\track.mp3', false],
            'bare filename' => ['track.mp3', false],
        ];
    }
}
