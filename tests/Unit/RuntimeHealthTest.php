<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace Tests\Unit;

use App\Services\RuntimeHealth;
use PHPUnit\Framework\TestCase;

class RuntimeHealthTest extends TestCase
{
    public function test_this_runtime_has_every_required_extension(): void
    {
        // The test suite itself runs on a PHP build; if it is missing something
        // we require, that is worth failing on directly.
        $health = new RuntimeHealth;

        $this->assertTrue(
            $health->isHealthy(),
            'Missing required extensions: '.implode(', ', array_keys($health->missingRequired())),
        );
        $this->assertSame([], $health->missingRequired());
    }

    public function test_a_missing_required_extension_is_named(): void
    {
        // Teeth: pretend `exif` is absent and assert the check both fails and
        // names the specific extension with its reason.
        $health = new class extends RuntimeHealth
        {
            protected function loaded(string $ext): bool
            {
                return $ext === 'exif' ? false : parent::loaded($ext);
            }
        };

        $this->assertFalse($health->isHealthy());
        $this->assertArrayHasKey('exif', $health->missingRequired());
        $this->assertStringContainsString('artwork', $health->missingRequired()['exif']);
    }

    public function test_a_missing_recommended_extension_does_not_fail_health(): void
    {
        // pcntl is POSIX-only; its absence is a warning, never a failure.
        $health = new class extends RuntimeHealth
        {
            protected function loaded(string $ext): bool
            {
                return $ext === 'pcntl' ? false : parent::loaded($ext);
            }
        };

        $this->assertTrue($health->isHealthy(), 'A missing recommended extension must not mark the runtime unhealthy.');
        $this->assertArrayHasKey('pcntl', $health->missingRecommended());
    }
}
