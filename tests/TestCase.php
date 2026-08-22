<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

abstract class TestCase extends BaseTestCase
{
    /**
     * Isolates every test from the real library and the network.
     *
     * The `local` disk points at storage/app/private, which holds the user's
     * actual films, music and books. LibraryOrganizer moves files and
     * DuplicateDetector deletes them, so a test running against the real disk
     * could destroy the thing it was written to protect. Faking is not a
     * convenience here — it is the reason these tests are safe to write.
     *
     * The network is stubbed for the same reason a suite should not need wifi:
     * a test that fails when TMDB is slow is a test nobody trusts.
     */
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Storage::fake('public');

        // Anything not explicitly faked by a test fails loudly rather than
        // reaching out. A silent real request would make the suite flaky and
        // could burn a rate limit.
        Http::preventStrayRequests();
    }

    /**
     * Writes a file to the faked disk and returns its absolute path.
     *
     * Content defaults to something distinct per path so two fixtures are not
     * accidentally byte-identical — duplicate detection would then see a
     * duplicate the test never intended.
     */
    protected function fixture(string $relativePath, ?string $contents = null): string
    {
        Storage::disk('local')->put($relativePath, $contents ?? 'fixture:' . $relativePath);

        return Storage::disk('local')->path($relativePath);
    }
}
