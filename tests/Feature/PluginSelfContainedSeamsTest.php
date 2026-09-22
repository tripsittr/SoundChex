<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace Tests\Feature;

use App\Plugins\Registry;
use Tests\TestCase;

/**
 * The seams that let a plugin be self-contained (S-314): its own routes,
 * migrations and service bindings, so a plugin can own a whole vertical rather
 * than being a UI over always-present core code.
 */
class PluginSelfContainedSeamsTest extends TestCase
{
    public function test_a_plugin_can_register_a_routes_file_with_prefix_and_middleware(): void
    {
        $registry = new Registry;

        $registry->forPlugin('acme.demo', function (Registry $r): void {
            $r->routes('/tmp/acme-routes.php', prefix: 'v1', middleware: ['auth:sanctum']);
        });

        $files = $registry->routeFiles();

        $this->assertCount(1, $files);
        $this->assertSame('acme.demo', $files[0]['plugin']);
        $this->assertSame('/tmp/acme-routes.php', $files[0]['path']);
        $this->assertSame('v1', $files[0]['prefix']);
        $this->assertSame(['auth:sanctum'], $files[0]['middleware']);
    }

    public function test_a_plugin_can_register_a_migration_directory(): void
    {
        $registry = new Registry;

        $registry->forPlugin('acme.demo', function (Registry $r): void {
            $r->migrations('/plugins/acme/migrations');
            $r->migrations('/plugins/acme/migrations'); // de-duplicated
        });

        $this->assertSame(['/plugins/acme/migrations'], $registry->migrationPaths());
    }

    public function test_a_plugin_can_bind_its_own_services(): void
    {
        $registry = app(Registry::class);

        $registry->forPlugin('acme.demo', function (Registry $r): void {
            $r->singleton('acme.demo.thing', fn () => new \stdClass);
            $r->binding('acme.demo.fresh', fn () => new \stdClass);
        });

        // The singleton returns the same instance; the plain binding a new one.
        $this->assertSame(app('acme.demo.thing'), app('acme.demo.thing'));
        $this->assertNotSame(app('acme.demo.fresh'), app('acme.demo.fresh'));
    }

    public function test_registered_plugin_routes_are_actually_routable(): void
    {
        // Write a tiny routes file and register it the way the provider does.
        $file = tempnam(sys_get_temp_dir(), 'plugroute').'.php';
        file_put_contents($file, <<<'PHP'
            <?php
            use Illuminate\Support\Facades\Route;
            Route::get('/plugin-seam-probe', fn () => response()->json(['ok' => true]));
            PHP);

        \Illuminate\Support\Facades\Route::prefix('api/v1')->group($file);

        $this->getJson('/api/v1/plugin-seam-probe')
            ->assertOk()
            ->assertJson(['ok' => true]);

        @unlink($file);
    }
}
