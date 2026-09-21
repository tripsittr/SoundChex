<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace Tests\Feature;

use App\Models\InstalledPlugin;
use App\Plugins\Exceptions\InvalidManifestException;
use App\Plugins\PluginLoader;
use App\Plugins\PluginManifest;
use App\Plugins\Registry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * The plugin loader (S-264, Phase 1): discovery, the enable gate, version
 * gating, runtime autoloading, and that one bad plugin cannot break the boot.
 *
 * The fixture plugin is referenced only by string — its class is autoloadable
 * *only* through the loader under test, so naming it at compile time would fail
 * before the loader has done its job. Lifecycle calls are read back from a cache
 * key the plugin writes to.
 */
class PluginLoaderTest extends TestCase
{
    use RefreshDatabase;

    private const CALLS_KEY = 'plugin-test.example.calls';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'soundchex.plugins.path' => base_path('tests/Fixtures/plugins'),
            'soundchex.plugins.enabled' => true,
            'soundchex.version' => '0.1.0',
        ]);

        $this->app->forgetInstance(Registry::class);
        $this->app->singleton(Registry::class);

        Cache::forget(self::CALLS_KEY);
    }

    private function loader(): PluginLoader
    {
        return new PluginLoader($this->app, $this->app->make(Registry::class));
    }

    /** @return array<int, string> the plugin lifecycle calls that were recorded */
    private function calls(): array
    {
        return Cache::get(self::CALLS_KEY, []);
    }

    private function enableExample(): void
    {
        InstalledPlugin::where('plugin_id', 'soundchex.example')->update(['enabled' => true]);
    }

    /* --------------------------------------------------------- discovery --- */

    public function test_discovery_records_a_plugin_disabled_by_default(): void
    {
        $this->loader()->discover();

        $row = InstalledPlugin::where('plugin_id', 'soundchex.example')->first();

        $this->assertNotNull($row);
        $this->assertFalse($row->enabled, 'A newly discovered plugin must land disabled.');
        $this->assertSame('Example Plugin', $row->name);
        $this->assertSame(['metadata-source'], $row->manifest['provides']);
    }

    public function test_a_rescan_never_re_enables_or_disables_a_plugin(): void
    {
        $this->loader()->discover();
        $this->enableExample();

        $this->loader()->discover();

        $this->assertTrue(
            (bool) InstalledPlugin::where('plugin_id', 'soundchex.example')->value('enabled'),
            'Re-scanning must leave the enabled state a human chose alone.',
        );
    }

    /* ------------------------------------------------------------- boot --- */

    public function test_an_enabled_plugin_loads_and_registers(): void
    {
        $this->loader()->discover();
        $this->enableExample();

        $loader = $this->loader();
        $loader->boot();

        $this->assertContains('register', $this->calls());

        $sources = $loader->registry()->metadataSourcesFor('music');
        $this->assertCount(1, $sources);
        $this->assertSame('soundchex.example', $sources[0]['plugin']);
        $this->assertSame(50, $sources[0]['priority']);
    }

    public function test_a_disabled_plugin_does_not_load(): void
    {
        $this->loader()->discover(); // lands disabled

        $loader = $this->loader();
        $loader->boot();

        $this->assertSame([], $this->calls());
        $this->assertSame([], $loader->registry()->metadataSourcesFor('music'));
    }

    public function test_the_master_switch_stops_all_loading(): void
    {
        $this->loader()->discover();
        $this->enableExample();
        config(['soundchex.plugins.enabled' => false]);

        $this->loader()->boot();

        $this->assertSame([], $this->calls());
    }

    public function test_an_incompatible_plugin_is_gated_out(): void
    {
        $this->loader()->discover();
        $this->enableExample();

        // The fixture needs server >= 0.1.0; pretend the server is older.
        config(['soundchex.version' => '0.0.9']);

        $this->loader()->boot();

        $this->assertSame([], $this->calls());
    }

    public function test_boot_runs_the_serving_time_lifecycle(): void
    {
        $this->loader()->discover();
        $this->enableExample();

        $loader = $this->loader();
        $loader->boot();
        $loader->bootLoaded();

        $this->assertSame(['register', 'boot'], $this->calls());
    }

    public function test_runtime_autoloading_reaches_the_plugins_own_classes(): void
    {
        // Not loadable before the loader runs — it is not in the app's autoload.
        $this->assertFalse(class_exists('SoundChex\\Example\\ExampleSource', false));

        $this->loader()->discover();
        $this->enableExample();
        $this->loader()->boot();

        // Now it resolves, with no composer dump — the loader registered PSR-4.
        $this->assertTrue(class_exists('SoundChex\\Example\\ExampleSource'));
    }

    /* --------------------------------------------------------- manifest --- */

    public function test_the_manifest_rejects_a_missing_required_field(): void
    {
        $this->expectException(InvalidManifestException::class);

        PluginManifest::fromArray(['id' => 'a.b', 'name' => 'X', 'version' => '1.0.0']); // no entrypoint
    }

    public function test_the_manifest_rejects_a_bad_id(): void
    {
        $this->expectException(InvalidManifestException::class);

        PluginManifest::fromArray([
            'id' => 'not a slug/at all',
            'name' => 'X', 'version' => '1.0.0', 'entrypoint' => 'X\\Plugin',
        ]);
    }

    public function test_the_manifest_gates_on_version(): void
    {
        $manifest = PluginManifest::fromArray([
            'id' => 'a.b', 'name' => 'X', 'version' => '1.0.0', 'entrypoint' => 'X\\Plugin',
            'minSoundChexVersion' => '0.5.0',
        ]);

        $this->assertFalse($manifest->isCompatibleWith('0.4.0', '1.0.0', PHP_VERSION));
        $this->assertTrue($manifest->isCompatibleWith('0.5.0', '1.0.0', PHP_VERSION));
    }
}
