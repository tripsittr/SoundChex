<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Plugins;

use App\Models\InstalledPlugin;
use App\Plugins\Contracts\SoundChexPlugin;
use App\Plugins\Exceptions\InvalidManifestException;
use Composer\Autoload\ClassLoader;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Log;

/**
 * Discovers, gates, autoloads and boots the installed plugins (S-264).
 *
 * A plugin is a directory under the plugins path holding a `plugin.json` and its
 * PHP under the namespace the manifest declares. The loader:
 *
 *   1. scans that path and reconciles what it finds against `installed_plugins`,
 *   2. skips anything disabled or incompatible with this server/PHP version,
 *   3. registers each remaining plugin's namespace with Composer's autoloader at
 *      runtime — no `composer dump-autoload`, so a plugin dropped in from the
 *      admin UI is loadable on the next request,
 *   4. instantiates the entry class through the container and calls `register()`.
 *
 * Everything is defensive: a broken plugin is logged and skipped, never fatal.
 * A plugin author's mistake must not take the whole server down with it — the
 * one place third-party code is trusted is also the one place it is most sand-
 * bagged against.
 */
class PluginLoader
{
    private bool $booted = false;

    /** @var array<int, array{plugin: SoundChexPlugin, id: string}> */
    private array $loaded = [];

    public function __construct(
        private readonly Application $app,
        private readonly Registry $registry,
    ) {}

    /**
     * Discovers plugins, reconciles the install table, and runs `register()` on
     * every enabled, compatible one. Idempotent — a second call is a no-op.
     */
    public function boot(): void
    {
        if ($this->booted) {
            return;
        }

        $this->booted = true;

        if (! config('soundchex.plugins.enabled', true)) {
            return;
        }

        foreach ($this->discover() as $directory => $manifest) {
            $this->load($directory, $manifest);
        }
    }

    /**
     * The plugin instances that loaded, for `boot()` wiring and diagnostics.
     *
     * @return array<int, array{plugin: SoundChexPlugin, id: string}>
     */
    public function loaded(): array
    {
        return $this->loaded;
    }

    public function registry(): Registry
    {
        return $this->registry;
    }

    /**
     * Reads every plugin directory's manifest, reconciling the install table so
     * a folder appearing or vanishing on disk is reflected in the database.
     *
     * @return array<string, PluginManifest> directory path => manifest
     */
    public function discover(): array
    {
        $path = config('soundchex.plugins.path');

        if (! is_string($path) || ! is_dir($path)) {
            return [];
        }

        $found = [];

        foreach (glob($path.'/*', GLOB_ONLYDIR) ?: [] as $directory) {
            $manifestPath = $directory.'/plugin.json';

            if (! is_file($manifestPath)) {
                continue;
            }

            try {
                $manifest = PluginManifest::fromFile($manifestPath);
            } catch (InvalidManifestException $e) {
                Log::warning('Skipping a plugin with an unreadable manifest', [
                    'directory' => $directory,
                    'error' => $e->getMessage(),
                ]);

                continue;
            }

            $this->reconcile($manifest, basename($directory));
            $found[$directory] = $manifest;
        }

        return $found;
    }

    /**
     * Writes (or updates) the install row for a discovered plugin, without ever
     * changing its enabled state — enabling is a human decision, and a re-scan
     * must not silently turn a plugin on. A brand-new plugin lands disabled.
     */
    private function reconcile(PluginManifest $manifest, string $directory): void
    {
        InstalledPlugin::query()->updateOrCreate(
            ['plugin_id' => $manifest->id],
            [
                'name' => $manifest->name,
                'version' => $manifest->version,
                'directory' => $directory,
                'manifest' => [
                    'id' => $manifest->id,
                    'name' => $manifest->name,
                    'version' => $manifest->version,
                    'author' => $manifest->author,
                    'description' => $manifest->description,
                    'provides' => $manifest->provides,
                    'minSoundChexVersion' => $manifest->minSoundChexVersion,
                    'requiresPhp' => $manifest->requiresPhp,
                    'license' => $manifest->license,
                ],
            ],
        );
    }

    /**
     * Loads one plugin: gate on enabled + compatibility, register autoloading,
     * instantiate the entry class, and collect its registrations. Any failure is
     * logged and swallowed so one bad plugin cannot break the boot.
     */
    private function load(string $directory, PluginManifest $manifest): void
    {
        try {
            $record = InstalledPlugin::query()->where('plugin_id', $manifest->id)->first();

            if ($record === null || ! $record->enabled) {
                return;
            }

            if (! $manifest->isCompatibleWith(config('soundchex.version', '0.0.0'), PHP_VERSION)) {
                Log::warning('Skipping an incompatible plugin', [
                    'plugin' => $manifest->id,
                    'needs_server' => $manifest->minSoundChexVersion,
                    'needs_php' => $manifest->requiresPhp,
                ]);

                return;
            }

            $this->registerAutoloading($manifest, $directory);

            if (! class_exists($manifest->entrypoint)) {
                Log::warning('A plugin entrypoint class does not exist', [
                    'plugin' => $manifest->id,
                    'entrypoint' => $manifest->entrypoint,
                ]);

                return;
            }

            $plugin = $this->app->make($manifest->entrypoint);

            if (! $plugin instanceof SoundChexPlugin) {
                Log::warning('A plugin entrypoint does not implement SoundChexPlugin', [
                    'plugin' => $manifest->id,
                    'entrypoint' => $manifest->entrypoint,
                ]);

                return;
            }

            $this->registry->forPlugin($manifest->id, fn (Registry $r) => $plugin->register($r));

            $this->loaded[] = ['plugin' => $plugin, 'id' => $manifest->id];
        } catch (\Throwable $e) {
            report($e);

            Log::error('A plugin failed to load', [
                'plugin' => $manifest->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Runs each loaded plugin's `boot()` — the serving-time lifecycle half.
     * Called once the app is actually handling a request, not during discovery.
     */
    public function bootLoaded(): void
    {
        foreach ($this->loaded as $entry) {
            try {
                $this->registry->forPlugin($entry['id'], fn (Registry $r) => $entry['plugin']->boot($r));
            } catch (\Throwable $e) {
                report($e);
            }
        }
    }

    /**
     * Registers the plugin's declared namespace with Composer's autoloader,
     * mapped to a `src/` directory in the plugin folder, so its classes load at
     * runtime with no `composer dump-autoload`.
     *
     * The namespace is inferred from the entrypoint: everything up to the last
     * segment. A plugin `Acme\Discogs\Plugin` maps `Acme\Discogs\` → `<dir>/src`.
     */
    private function registerAutoloading(PluginManifest $manifest, string $directory): void
    {
        $namespace = $this->namespaceOf($manifest->entrypoint);

        if ($namespace === null) {
            return;
        }

        $loader = $this->composerLoader();

        if ($loader === null) {
            return;
        }

        // `$directory` is the plugin's own absolute folder (from discovery); the
        // code lives in its `src/`. PSR-4 keys carry a trailing separator, and
        // the entrypoint's file sits under this root — Acme\Discogs\Plugin →
        // src/Plugin.php.
        $loader->addPsr4($namespace.'\\', $directory.'/src');
    }

    /** The namespace part of a fully-qualified class name, or null if it has none. */
    private function namespaceOf(string $class): ?string
    {
        $class = ltrim($class, '\\');
        $pos = strrpos($class, '\\');

        return $pos === false ? null : substr($class, 0, $pos);
    }

    /**
     * Composer's registered ClassLoader instance, the one already autoloading the
     * app, so plugin namespaces join the same map. Null if it cannot be found —
     * in which case autoloading is left to whatever else may register it.
     */
    private function composerLoader(): ?ClassLoader
    {
        foreach (spl_autoload_functions() ?: [] as $function) {
            if (is_array($function) && ($function[0] ?? null) instanceof ClassLoader) {
                return $function[0];
            }
        }

        return null;
    }
}
