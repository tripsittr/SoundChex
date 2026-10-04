<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace Tests\Feature;

use App\Filesystem\WindowsSafeFilesystem;
use Illuminate\Filesystem\Filesystem;
use Tests\TestCase;

/**
 * Laravel's `replace()` writes a temp file and renames it over the target.
 * Windows refuses that rename while anything else holds the target open, and
 * under FrankenPHP two requests compiling the same Blade view do exactly that
 * — the loser returned a failed page.
 *
 * Holding a handle open while replacing is the same condition, and it is
 * reproducible.
 */
class WindowsSafeFilesystemTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        parent::setUp();

        $this->path = storage_path('framework/testing/replace-'.getmypid().'.txt');

        @mkdir(dirname($this->path), 0755, true);
        file_put_contents($this->path, 'original');
    }

    protected function tearDown(): void
    {
        @unlink($this->path);

        parent::tearDown();
    }

    public function test_it_replaces_a_file_that_is_currently_open(): void
    {
        $handle = fopen($this->path, 'r');
        $this->assertNotFalse($handle);

        try {
            (new WindowsSafeFilesystem)->replace($this->path, 'rewritten');

            $this->assertSame('rewritten', file_get_contents($this->path));
        } finally {
            fclose($handle);
        }
    }

    /**
     * The condition the fix exists for, asserted directly: on Windows the stock
     * implementation cannot do this, which is why ours is bound in its place.
     */
    public function test_the_stock_implementation_is_the_one_that_cannot(): void
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            $this->markTestSkipped('rename over an open file is only refused on Windows.');
        }

        $handle = fopen($this->path, 'r');

        try {
            $failed = false;

            try {
                @(new Filesystem)->replace($this->path, 'rewritten');
            } catch (\Throwable) {
                $failed = true;
            }

            // Either it threw, or it quietly left the original in place.
            $this->assertTrue(
                $failed || file_get_contents($this->path) === 'original',
                'If stock replace() now works on Windows, this override can go.',
            );
        } finally {
            fclose($handle);
        }
    }

    public function test_it_creates_the_directory_when_missing(): void
    {
        $nested = storage_path('framework/testing/nested-'.getmypid().'/file.txt');

        (new WindowsSafeFilesystem)->replace($nested, 'made');

        $this->assertSame('made', file_get_contents($nested));

        @unlink($nested);
        @rmdir(dirname($nested));
    }

    public function test_the_container_hands_out_the_safe_one(): void
    {
        $this->assertInstanceOf(WindowsSafeFilesystem::class, app('files'));
    }
}
