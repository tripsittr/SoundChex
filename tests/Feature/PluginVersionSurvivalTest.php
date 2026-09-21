<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace Tests\Feature;

use App\Plugins\PluginManifest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Plugins survive app releases unless a major plugin-API overhaul (S-264).
 *
 * A plugin declares the plugin-API version it was built for (`targetApi`); it
 * keeps loading across minor and patch API bumps and is refused only when the
 * major changes — the deliberate breaking overhaul. These pin that rule so a
 * later change to the gate cannot silently start breaking installed plugins.
 */
class PluginVersionSurvivalTest extends TestCase
{
    private function manifest(?string $targetApi, ?string $minServer = null): PluginManifest
    {
        return PluginManifest::fromArray(array_filter([
            'id' => 'acme.demo',
            'name' => 'Demo',
            'version' => '1.0.0',
            'entrypoint' => 'Acme\\Demo\\Plugin',
            'targetApi' => $targetApi,
            'minSoundChexVersion' => $minServer,
        ]));
    }

    #[DataProvider('apiCases')]
    public function test_the_plugin_api_major_rule(string $targetApi, string $serverApi, bool $compatible): void
    {
        $this->assertSame(
            $compatible,
            $this->manifest($targetApi)->isCompatibleWith('9.9.9', $serverApi, PHP_VERSION),
        );
    }

    public static function apiCases(): array
    {
        return [
            // Built for 1.0, survives every 1.x — the whole point.
            'same version' => ['1.0.0', '1.0.0', true],
            'server minor ahead' => ['1.2.0', '1.5.0', true],
            'server far ahead in minor' => ['1.0.0', '1.9.0', true],
            'server patch ahead' => ['1.2.0', '1.2.9', true],

            // A newer major on the server is the overhaul — old plugin refused.
            'server major overhaul' => ['1.5.0', '2.0.0', false],

            // A plugin built for a newer API than the server has cannot run.
            'plugin ahead of server minor' => ['1.5.0', '1.2.0', false],
            'plugin ahead of server major' => ['2.0.0', '1.9.0', false],
        ];
    }

    public function test_a_plugin_without_a_target_api_still_loads(): void
    {
        // The existing plugins have no targetApi; they must keep working. Only
        // the server + php floors apply to them.
        $this->assertTrue(
            $this->manifest(targetApi: null)->isCompatibleWith('1.0.0', '5.0.0', PHP_VERSION),
        );
    }

    public function test_the_server_version_floor_still_applies(): void
    {
        $m = $this->manifest(targetApi: '1.0.0', minServer: '0.5.0');

        $this->assertFalse($m->isCompatibleWith('0.4.0', '1.0.0', PHP_VERSION));
        $this->assertTrue($m->isCompatibleWith('0.5.0', '1.0.0', PHP_VERSION));
    }

    public function test_the_incompatibility_reason_explains_a_major_overhaul(): void
    {
        $reason = $this->manifest(targetApi: '1.0.0')
            ->incompatibilityReason('9.9.9', '2.0.0', PHP_VERSION);

        $this->assertNotNull($reason);
        $this->assertStringContainsString('plugin API', $reason);
    }
}
