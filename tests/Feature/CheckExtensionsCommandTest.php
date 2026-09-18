<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace Tests\Feature;

use App\Services\RuntimeHealth;
use Tests\TestCase;

class CheckExtensionsCommandTest extends TestCase
{
    public function test_it_succeeds_when_all_required_extensions_are_present(): void
    {
        $this->artisan('server:check-extensions')
            ->assertExitCode(0);
    }

    public function test_it_fails_and_names_the_missing_extension(): void
    {
        // Bind a RuntimeHealth that reports `curl` missing, and assert the
        // command exits non-zero and prints the extension name.
        $this->app->bind(RuntimeHealth::class, fn () => new class extends RuntimeHealth
        {
            protected function loaded(string $ext): bool
            {
                return $ext === 'curl' ? false : parent::loaded($ext);
            }
        });

        $this->artisan('server:check-extensions')
            ->expectsOutputToContain('curl')
            ->assertExitCode(1);
    }
}
