<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace Tests\Feature;

use Tests\TestCase;

/**
 * The library, the cache, the sessions and the queue all live in one SQLite
 * file, so anything that holds a write open blocks everything else.
 *
 * A scan that imports several files dispatches a burst of enrichment jobs.
 * Those jobs make slow network calls — and for music, up to 60s of Chromaprint
 * fingerprinting — before they write. With a 10s busy timeout they knocked each
 * other over as "database is locked", silently, every five minutes: twelve
 * dropped enrichment jobs in one evening, each one a file that never got its
 * metadata.
 *
 * SQLite waits rather than failing when the timeout allows it, so the timeout
 * has to clear the slowest thing that can hold a write.
 */
class DatabaseConcurrencyTest extends TestCase
{
    /**
     * The longest a single enrichment job can hold a connection: Chromaprint
     * fingerprinting, plus the AcoustID lookup that follows it with retries.
     */
    private const SLOWEST_HOLD_MS = 60_000 + (15_000 * 3);

    public function test_the_busy_timeout_outlasts_the_slowest_write_holder(): void
    {
        $timeout = (int) config('database.connections.sqlite.busy_timeout');

        $this->assertGreaterThanOrEqual(
            self::SLOWEST_HOLD_MS,
            $timeout,
            'A job can hold a write open longer than the busy timeout allows, so '
            . 'concurrent writers will be dropped as "database is locked".',
        );
    }

    public function test_write_ahead_logging_is_on(): void
    {
        // Without WAL a reader blocks every writer, and the timeout above only
        // determines how long they wait before giving up.
        $this->assertSame('WAL', config('database.connections.sqlite.journal_mode'));
    }

    public function test_the_fingerprint_timeout_has_not_outgrown_the_budget(): void
    {
        // If fingerprinting is ever given longer, this fails rather than
        // quietly reintroducing dropped jobs.
        $source = file_get_contents(app_path('Services/Metadata/Sources/Music/AcoustId.php'));

        preg_match('/Process::timeout\((\d+)\)/', $source, $matches);

        $this->assertNotEmpty($matches, 'Could not find the fingerprint timeout.');
        $this->assertLessThanOrEqual(
            60,
            (int) $matches[1],
            'Fingerprinting may now hold a write open longer than the busy timeout covers.',
        );
    }
}
