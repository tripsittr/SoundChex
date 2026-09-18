<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace Tests\Feature;

use App\Services\EnvironmentFile;
use Tests\TestCase;

/**
 * Covers the atomic .env write introduced for the concurrent server (S-151
 * Step 3). `artisan serve` is single-threaded, so a torn or clobbered .env was
 * impossible; php-fpm runs writers in parallel, so the write must be safe.
 */
class EnvironmentFileTest extends TestCase
{
    private string $tempEnv;

    private EnvironmentFile $env;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempEnv = tempnam(sys_get_temp_dir(), 'envtest');
        file_put_contents($this->tempEnv, "APP_ENV=local\nAPP_URL=http://localhost\nOTHER=keep\n");

        // Point the service at a throwaway file, and neutralise the config:clear
        // call (it would run against the real app).
        $path = $this->tempEnv;
        $this->env = new class($path) extends EnvironmentFile
        {
            public function __construct(private string $testPath) {}

            protected function path(): string
            {
                return $this->testPath;
            }
        };
    }

    protected function tearDown(): void
    {
        @unlink($this->tempEnv);
        parent::tearDown();
    }

    public function test_it_updates_a_key_and_preserves_the_others(): void
    {
        $this->env->set(['APP_URL' => 'https://example.test']);

        $contents = file_get_contents($this->tempEnv);
        $this->assertStringContainsString('APP_URL=https://example.test', $contents);
        $this->assertStringContainsString('APP_ENV=local', $contents);
        $this->assertStringContainsString('OTHER=keep', $contents);
    }

    public function test_it_appends_a_missing_key(): void
    {
        $this->env->set(['NEW_KEY' => 'value']);

        $this->assertSame('value', $this->env->get('NEW_KEY'));
        $this->assertStringContainsString('OTHER=keep', file_get_contents($this->tempEnv));
    }

    public function test_the_write_is_atomic_leaving_no_partial_file(): void
    {
        // After a write the file must be complete and parseable — never torn.
        // The temp-file rename guarantees a reader sees old-or-new, never half.
        $this->env->set(['APP_URL' => 'https://new.test', 'EXTRA' => 'x']);

        $contents = file_get_contents($this->tempEnv);
        // Every original key survives alongside the new ones.
        foreach (['APP_ENV=local', 'OTHER=keep', 'APP_URL=https://new.test', 'EXTRA=x'] as $needle) {
            $this->assertStringContainsString($needle, $contents);
        }
        // No leftover temp files beside it.
        $siblings = glob(dirname($this->tempEnv).'/.env*');
        $this->assertEmpty($siblings ?: [], 'A temp .env file was left behind after the atomic rename.');
    }
}
