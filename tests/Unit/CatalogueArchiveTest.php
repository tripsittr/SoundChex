<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace Tests\Unit;

use App\Services\CatalogueArchive;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Compressing the catalogue for another server to fetch.
 *
 * This is the step that broke the first real transfer between two machines:
 * `gzopen('php://output')` fails with "could not make seekable", so the
 * endpoint answered 500 where the catalogue should have been.
 *
 * The test written for that fix re-implemented the compression rather than
 * calling it, which meant reverting the fix left it green — it asserted that
 * gzip works, which was never in doubt. These call the real class, so putting
 * `php://output` back turns them red: nothing is written to the destination
 * and there is no archive to read.
 */
class CatalogueArchiveTest extends TestCase
{
    private string $source;

    protected function setUp(): void
    {
        parent::setUp();

        // A scratch file, never a real catalogue. Everything here is created
        // and removed by the test.
        $this->source = tempnam(sys_get_temp_dir(), 'soundchex-test-src-');

        file_put_contents(
            $this->source,
            'SQLite format 3' . "\0" . str_repeat('catalogue bytes ', 4096),
        );
    }

    protected function tearDown(): void
    {
        foreach ([$this->source, $this->source . '.gz', $this->source . '-elsewhere.gz'] as $path) {
            @unlink($path);
        }

        parent::tearDown();
    }

    public function test_it_writes_an_archive_that_unpacks_to_the_original(): void
    {
        $archive = (new CatalogueArchive)->compress($this->source);

        // The file has to exist and hold something. Compressing to output
        // rather than to a file satisfies neither.
        $this->assertFileExists($archive);
        $this->assertGreaterThan(0, filesize($archive));

        $unpacked = gzdecode(file_get_contents($archive));

        $this->assertNotFalse($unpacked, 'The archive was not gzip.');
        $this->assertSame(file_get_contents($this->source), $unpacked);
    }

    public function test_it_actually_compresses(): void
    {
        // 85% on the real 24 MB database. Repetitive scratch data does better
        // than that, so the assertion is only that it is meaningfully smaller
        // — the point is that gzip ran, not that it hit a particular ratio.
        $archive = (new CatalogueArchive)->compress($this->source);

        $this->assertLessThan(filesize($this->source), filesize($archive));
    }

    public function test_it_writes_where_it_is_told(): void
    {
        $destination = $this->source . '-elsewhere.gz';

        $this->assertSame($destination, (new CatalogueArchive)->compress($this->source, $destination));
        $this->assertFileExists($destination);
    }

    public function test_it_refuses_a_source_it_cannot_read(): void
    {
        // A caller that mistook this for an empty catalogue would ship an
        // empty catalogue, and the receiver would replace a real one with it.
        $this->expectException(RuntimeException::class);

        (new CatalogueArchive)->compress($this->source . '-does-not-exist');
    }

    public function test_it_refuses_a_destination_it_cannot_write(): void
    {
        $this->expectException(RuntimeException::class);

        (new CatalogueArchive)->compress(
            $this->source,
            $this->source . '-no-such-directory/archive.gz',
        );
    }
}
