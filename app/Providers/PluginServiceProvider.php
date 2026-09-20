<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Providers;

use App\Plugins\PluginLoader;
use App\Plugins\Registry;
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
        // The install table has to exist before discovery reads it. During the
        // very first migration (or in a bare test that has not migrated) it may
        // not — so guard, rather than fail the whole boot on a missing table.
        if (! $this->installTableReady()) {
            return;
        }

        $loader = $this->app->make(PluginLoader::class);
        $loader->boot();

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
     * Whether the installed_plugins table is present. Discovery reads it, so a
     * boot before the table exists (first migrate, or an un-migrated test) must
     * skip the loader rather than throw.
     */
    private function installTableReady(): bool
    {
        try {
            return $this->app['db']->getSchemaBuilder()->hasTable('installed_plugins');
        } catch (\Throwable) {
            return false;
        }
    }
}
