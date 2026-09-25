<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace Tests;

use App\Enums\ProcessingStatus;
use App\Models\MediaItem;
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

        // An item created without a status takes the column default,
        // `pending` — which the library now hides as "not certain yet"
        // (S-396). In production nothing stays pending: the scanner sets it,
        // enrichment moves it to complete, needs_review or failed. In a test
        // there is no enrichment, so 100-odd fixtures across the suite would
        // silently become invisible and every assertion about them would fail
        // for a reason that has nothing to do with what the test is checking.
        //
        // So a test fixture is complete unless it says otherwise, and the
        // tests that care about an unresolved item set the status explicitly
        // — which reads better anyway: the status is stated where it matters
        // instead of inherited from a column default.
        MediaItem::creating(function (MediaItem $item): void {
            if ($item->getAttribute('processing_status') === null) {
                $item->setAttribute('processing_status', ProcessingStatus::Complete);
            }
        });
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
        Storage::disk('local')->put($relativePath, $contents ?? 'fixture:'.$relativePath);

        return Storage::disk('local')->path($relativePath);
    }
}
