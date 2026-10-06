<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace Tests\Unit;

use App\Services\FileIdentity;
use PHPUnit\Framework\TestCase;

/**
 * This class gates every delete in the library, so its *refusals* matter as
 * much as its matches: a false "same file" skips a delete harmlessly, while a
 * false "different file" is what destroyed a user's only copy (#489).
 */
class FileIdentityTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = sys_get_temp_dir().'/sc-identity-'.bin2hex(random_bytes(6));
        mkdir($this->directory, 0775, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory.'/*') ?: [] as $path) {
            is_dir($path) ? @rmdir($path) : @unlink($path);
        }

        @rmdir($this->directory);

        parent::tearDown();
    }

    public function test_a_path_is_the_same_file_as_itself(): void
    {
        $path = $this->write('a.mp3', 'bytes');

        $this->assertTrue(FileIdentity::same($path, $path));
    }

    public function test_two_distinct_files_with_identical_contents_are_not_the_same_file(): void
    {
        // The case that must never be confused with a case-only rename: these
        // really are two files, and one of them is genuinely redundant.
        $a = $this->write('a.mp3', 'identical bytes');
        $b = $this->write('b.mp3', 'identical bytes');

        $this->assertFalse(FileIdentity::same($a, $b));
        $this->assertFalse(FileIdentity::sameInode($a, $b));
    }

    public function test_a_case_only_difference_is_the_same_file_on_a_case_insensitive_volume(): void
    {
        $path = $this->write('Chicago.mp3', 'the only copy');
        $recased = $this->directory.'/CHICAGO.mp3';

        if (! file_exists($recased)) {
            $this->markTestSkipped('This volume is case-sensitive, so the two spellings are two files.');
        }

        // The whole point: the strings differ, the file does not.
        $this->assertNotSame($path, $recased);
        $this->assertTrue(FileIdentity::same($path, $recased));
        $this->assertTrue(FileIdentity::sameInode($path, $recased));
    }

    public function test_a_hardlink_is_the_same_file(): void
    {
        $path = $this->write('original.mp3', 'bytes');
        $link = $this->directory.'/hardlink.mp3';

        if (! @link($path, $link)) {
            $this->markTestSkipped('This filesystem does not support hard links.');
        }

        $this->assertTrue(FileIdentity::sameInode($path, $link));
    }

    public function test_a_symlinked_parent_resolves_to_the_same_file(): void
    {
        // A symlinked library root is the other way one file gets two names.
        mkdir($this->directory.'/real', 0775, true);
        $path = $this->directory.'/real/track.mp3';
        file_put_contents($path, 'bytes');

        $linkedDirectory = $this->directory.'/linked';

        if (! @symlink($this->directory.'/real', $linkedDirectory)) {
            $this->markTestSkipped('This filesystem does not support symlinks.');
        }

        $this->assertTrue(FileIdentity::same($path, $linkedDirectory.'/track.mp3'));

        @unlink($linkedDirectory);
        @unlink($path);
        @rmdir($this->directory.'/real');
    }

    public function test_a_relative_traversal_resolves_to_the_same_file(): void
    {
        $path = $this->write('track.mp3', 'bytes');
        $viaDot = $this->directory.'/./track.mp3';

        $this->assertTrue(FileIdentity::same($path, $viaDot));
    }

    public function test_a_missing_path_is_never_the_same_file(): void
    {
        // "Not there" and "there" are not the same file — and answering yes
        // would let a delete proceed against a path that no longer exists.
        $path = $this->write('a.mp3', 'bytes');

        $this->assertFalse(FileIdentity::same($path, $this->directory.'/gone.mp3'));
        $this->assertFalse(FileIdentity::same($this->directory.'/gone.mp3', $path));
        $this->assertFalse(FileIdentity::sameInode($this->directory.'/gone.mp3', $this->directory.'/also-gone.mp3'));
    }

    private function write(string $name, string $contents): string
    {
        $path = $this->directory.'/'.$name;
        file_put_contents($path, $contents);

        return $path;
    }
}
