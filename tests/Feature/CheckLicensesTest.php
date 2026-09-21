<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace Tests\Feature;

use Illuminate\Support\Facades\Process;
use Tests\TestCase;

/**
 * The Composer licence gate (S-5): it passes a clean tree and — the part that
 * matters — fails a tree with a dependency that forbids AGPL distribution.
 */
class CheckLicensesTest extends TestCase
{
    private function fakeLicenses(array $dependencies): void
    {
        Process::fake([
            '*' => Process::result(
                output: json_encode(['dependencies' => $dependencies]),
            ),
        ]);
    }

    public function test_it_passes_a_clean_tree(): void
    {
        $this->fakeLicenses([
            'acme/mit' => ['license' => ['MIT']],
            'acme/bsd' => ['license' => ['BSD-3-Clause']],
        ]);

        $this->artisan('licenses:check')->assertSuccessful();
    }

    public function test_a_multi_licensed_package_passes_on_its_compatible_option(): void
    {
        // The real case: nette lists GPL alongside BSD-3-Clause. One compatible
        // option is enough — the package must pass.
        $this->fakeLicenses([
            'nette/utils' => ['license' => ['BSD-3-Clause', 'GPL-2.0-only', 'GPL-3.0-only']],
        ]);

        $this->artisan('licenses:check')->assertSuccessful();
    }

    public function test_it_fails_a_gpl_2_only_dependency_with_no_alternative(): void
    {
        $this->fakeLicenses([
            'acme/good' => ['license' => ['MIT']],
            'acme/bad' => ['license' => ['GPL-2.0-only']], // no permissive option
        ]);

        $this->artisan('licenses:check')
            ->expectsOutputToContain('acme/bad')
            ->assertFailed();
    }

    public function test_it_fails_an_sspl_dependency(): void
    {
        $this->fakeLicenses([
            'acme/mongo-ish' => ['license' => ['SSPL-1.0']],
        ]);

        $this->artisan('licenses:check')->assertFailed();
    }

    public function test_it_fails_an_unlicensed_dependency(): void
    {
        // No licence is "all rights reserved" — cannot be redistributed.
        $this->fakeLicenses([
            'acme/nolicense' => ['license' => []],
        ]);

        $this->artisan('licenses:check')->assertFailed();
    }
}
