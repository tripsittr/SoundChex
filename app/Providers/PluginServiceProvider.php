<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Providers;

use App\Plugins\PluginLoader;
use App\Plugins\Registry;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * Wires the plugin platform into the application boot (S-264).
 *
 * The registry and loader are singletons so every part of the app reads the
 * same set of plugin contributions. `boot()` runs discovery once, after the
 * rest of the app's providers have registered — so a plugin's `register()` can
 * rely on the core services being bound — and defers each plugin's own `boot()`
 * to when the app is actually serving.
 */
class PluginServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(Registry::class);

        $this->app->singleton(PluginLoader::class, fn ($app) => new PluginLoader(
            $app,
            $app->make(Registry::class),
        ));
    }

    public function boot(): void
    {
        // The loader handles a missing install table itself (bundled plugins load
        // regardless; installed ones wait for the table), so it is always safe
        // to boot it here.
        $loader = $this->app->make(PluginLoader::class);
        $loader->boot();

        // Plugins that ship their own vertical (S-314): load the routes and
        // migrations they registered. Both run only for enabled plugins, because
        // the loader only ran the `register()` of enabled ones — so disabling a
        // plugin removes its endpoints and stops its migrations being offered.
        $registry = $this->app->make(Registry::class);

        $this->registerPluginRoutes($registry);
        $this->registerPluginMigrations($registry);

        // Each plugin's serving-time boot runs once the app is handling a
        // request, kept off console and queue boots where it has no business.
        $this->app->booted(function () use ($loader): void {
            if ($this->app->runningInConsole()) {
                return;
            }

            $loader->bootLoaded();
        });
    }

    /**
     * Load each enabled plugin's routes file, wrapped in the prefix and
     * middleware it asked for (S-314). Non-fatal per plugin: a broken routes file
     * is logged and skipped rather than taking the whole app's routing down.
     */
    private function registerPluginRoutes(Registry $registry): void
    {
        foreach ($registry->routeFiles() as $route) {
            if (! is_file($route['path'])) {
                continue;
            }

            try {
                $registrar = Route::middleware($route['middleware']);

                if ($route['prefix'] !== null) {
                    $registrar = $registrar->prefix($route['prefix']);
                }

                $registrar->group($route['path']);
            } catch (\Throwable $e) {
                report($e);
            }
        }
    }

    /**
     * Register each enabled plugin's migration directory with Laravel's migrator,
     * so `migrate` runs a plugin's own schema alongside the core migrations.
     */
    private function registerPluginMigrations(Registry $registry): void
    {
        $paths = array_filter($registry->migrationPaths(), 'is_dir');

        if ($paths !== []) {
            $this->loadMigrationsFrom($paths);
        }
    }
}
