<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Providers;

use App\Plugins\PluginLoader;
use App\Plugins\Registry;
use Filament\Support\Facades\FilamentView;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Log;
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
        $this->registerSlotDirective();

        // Each plugin's serving-time boot runs once the app is handling a
        // request, kept off console and queue boots where it has no business.
        $this->app->booted(function () use ($loader, $registry): void {
            if ($this->app->runningInConsole()) {
                return;
            }

            $loader->bootLoaded();

            // After bootLoaded(), because that is when a plugin's boot() runs
            // and registers its hooks — applying them before would apply an
            // empty list (S-316).
            $this->registerPluginRenderHooks($registry);
        });
    }

    /**
     * `@pluginSlot('name', $context)` in the media center's Blade (S-318).
     *
     * The admin panel takes its positions from Filament; the player has no
     * such system, so its slots are placed by hand in the templates. The
     * directive resolves the registry at render time rather than closing over
     * it, so a plugin enabled during this request is still seen.
     *
     * Output is deliberately NOT escaped: a slot exists to let a plugin
     * contribute markup. That is the same trust already extended to a plugin's
     * admin page and its render hooks — a plugin runs as the server.
     */
    private function registerSlotDirective(): void
    {
        Blade::directive('pluginSlot', function (string $expression): string {
            return "<?php echo app(\\App\\Plugins\\Registry::class)->renderSlot({$expression}); ?>";
        });
    }

    /**
     * Hand each plugin's render hooks to Filament (S-316).
     *
     * A hook is a named position in the panel's chrome; the plugin supplies a
     * callback returning markup. Wrapped per hook so a plugin that throws
     * renders nothing there rather than taking the whole page down with it —
     * the same posture as the routes and migrations seams.
     */
    private function registerPluginRenderHooks(Registry $registry): void
    {
        foreach ($registry->renderHooks() as $entry) {
            FilamentView::registerRenderHook(
                $entry['hook'],
                function () use ($entry): string {
                    try {
                        return (string) ($entry['callback'])();
                    } catch (\Throwable $e) {
                        Log::warning('plugin render hook failed', [
                            'plugin' => $entry['plugin'],
                            'hook' => $entry['hook'],
                            'error' => $e->getMessage(),
                        ]);

                        return '';
                    }
                },
            );
        }
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
