<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace Tests\Feature;

use App\Models\InstalledPlugin;
use App\Plugins\PluginLoader;
use App\Plugins\PluginManifest;
use App\Plugins\Registry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The plugin scaffold (S-264 Phase 5): `plugin:make` writes a complete, valid,
 * loadable plugin — an author edits something that already runs.
 */
class PluginMakeTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'soundchex.version' => '0.1.0',
            'soundchex.plugins.path' => $this->dir = base_path('storage/framework/testing/scaffold-'.uniqid()),
        ]);
        @mkdir($this->dir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->deleteDir($this->dir);
        parent::tearDown();
    }

    public function test_it_scaffolds_a_valid_manifest_and_entry_class(): void
    {
        $this->artisan('plugin:make', ['id' => 'acme.demo', '--author' => 'Acme'])
            ->assertSuccessful();

        $base = $this->dir.'/acme.demo';

        $this->assertFileExists($base.'/plugin.json');
        $this->assertFileExists($base.'/src/Plugin.php');
        $this->assertFileExists($base.'/src/ExampleSource.php');

        // The manifest is valid and its entrypoint namespace matches the id.
        $manifest = PluginManifest::fromFile($base.'/plugin.json');
        $this->assertSame('acme.demo', $manifest->id);
        $this->assertSame('Acme\\Demo\\Plugin', $manifest->entrypoint);
    }

    public function test_the_scaffolded_plugin_loads_and_registers(): void
    {
        $this->artisan('plugin:make', ['id' => 'acme.demo'])->assertSuccessful();

        $loader = new PluginLoader($this->app, app(Registry::class));
        $loader->discover();
        InstalledPlugin::where('plugin_id', 'acme.demo')->update(['enabled' => true]);
        $loader->boot();

        // Its sample source is registered, and its class autoloaded at runtime.
        $this->assertCount(1, $loader->registry()->metadataSourcesFor('music'));
        $this->assertTrue(class_exists('Acme\\Demo\\ExampleSource'));
    }

    public function test_it_rejects_a_bad_id(): void
    {
        $this->artisan('plugin:make', ['id' => 'not a slug'])
            ->assertFailed();
    }

    public function test_it_refuses_to_overwrite_an_existing_plugin(): void
    {
        $this->artisan('plugin:make', ['id' => 'acme.demo'])->assertSuccessful();
        $this->artisan('plugin:make', ['id' => 'acme.demo'])->assertFailed();
    }

    private function deleteDir(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir.'/'.$entry;
            is_dir($path) ? $this->deleteDir($path) : @unlink($path);
        }

        @rmdir($dir);
    }
}
