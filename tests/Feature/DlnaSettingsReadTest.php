<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace Tests\Feature;

use App\Console\Commands\DlnaServe;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Tests\TestCase;

/**
 * Reading the DLNA settings inside dlna:serve's loop (S-7).
 *
 * A Feature test only for the Log facade; nothing here opens a socket.
 */
class DlnaSettingsReadTest extends TestCase
{
    /**
     * A failed read keeps the last state. Returning "off" sent a byebye and,
     * five seconds later, an alive: every TV dropping the server and adding
     * it back because SQLite was briefly locked.
     */
    public function test_a_failed_read_keeps_the_last_known_state(): void
    {
        Log::spy();
        $failing = false;

        $locked = fn (): bool => throw new RuntimeException('database is locked');

        $this->assertTrue(DlnaServe::readOr($locked, true, $failing));
        $this->assertFalse(DlnaServe::readOr($locked, false, $failing));
        $this->assertTrue($failing);
    }

    /** Logged once per run of failures, not every five seconds while it lasts. */
    public function test_a_run_of_failures_is_logged_once(): void
    {
        Log::spy();
        $failing = false;

        $locked = fn (): bool => throw new RuntimeException('database is locked');

        DlnaServe::readOr($locked, true, $failing);
        DlnaServe::readOr($locked, true, $failing);
        DlnaServe::readOr($locked, true, $failing);

        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message, array $context): bool => str_contains($message, 'could not read the DLNA settings')
                && $context['error'] === 'database is locked')
            ->once();

        // Readable again: the value read, not the one kept.
        $this->assertFalse(DlnaServe::readOr(fn (): bool => false, true, $failing));
        $this->assertFalse($failing);

        Log::shouldHaveReceived('info')->with('DLNA: settings readable again')->once();
    }

    /** The UUID read, which writes on first use, is guarded the same way. */
    public function test_an_identity_read_that_fails_yields_nothing_rather_than_throwing(): void
    {
        Log::spy();
        $failing = false;

        $this->assertNull(DlnaServe::readOr(fn (): array => throw new RuntimeException('no such table: settings'), null, $failing));
    }
}
