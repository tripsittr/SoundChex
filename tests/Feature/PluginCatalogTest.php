<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace Tests\Feature;

use App\Plugins\Catalog\CatalogEntry;
use App\Plugins\Catalog\PluginCatalog;
use App\Plugins\Catalog\PluginInstaller;
use App\Plugins\Exceptions\PluginInstallException;
use App\Plugins\PluginLoader;
use App\Plugins\Registry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;
use ZipArchive;

/**
 * The plugin catalog and installer (S-264 Phase 4b): reading a repository,
 * gating on compatibility, and installing a downloaded plugin safely — the one
 * place the server pulls third-party code, so the checks are tested hardest.
 */
class PluginCatalogTest extends TestCase
{
    use RefreshDatabase;

    private string $pluginsDir;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'soundchex.version' => '0.2.0',
            'soundchex.plugins.path' => $this->pluginsDir = base_path('storage/framework/testing/plugins-'.uniqid()),
        ]);
        @mkdir($this->pluginsDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->deleteDir($this->pluginsDir);
        parent::tearDown();
    }

    /* ---------------------------------------------------------- catalog --- */

    public function test_the_catalog_picks_the_newest_compatible_version(): void
    {
        Http::fake(['repo.test/*' => Http::response([[
            'id' => 'acme.demo',
            'name' => 'Demo',
            'versions' => [
                ['version' => '2.0.0', 'sourceUrl' => 'x', 'targetAbi' => '9.9.9'], // too new
                ['version' => '1.5.0', 'sourceUrl' => 'x', 'targetAbi' => '0.1.0'], // fits
                ['version' => '1.0.0', 'sourceUrl' => 'x', 'targetAbi' => '0.1.0'],
            ],
        ]])]);

        $entries = app(PluginCatalog::class)->fetch('https://repo.test/manifest.json');

        $this->assertCount(1, $entries);
        $this->assertSame('1.5.0', $entries[0]->version());
        $this->assertTrue($entries[0]->isInstallable());
    }

    public function test_a_plugin_with_no_compatible_version_is_listed_but_not_installable(): void
    {
        Http::fake(['repo.test/*' => Http::response([[
            'id' => 'acme.future',
            'name' => 'Future',
            'versions' => [['version' => '3.0.0', 'sourceUrl' => 'x', 'targetAbi' => '9.9.9']],
        ]])]);

        $entries = app(PluginCatalog::class)->fetch('https://repo.test/manifest.json');

        $this->assertCount(1, $entries);
        $this->assertFalse($entries[0]->isInstallable());
    }

    public function test_an_unreachable_repository_yields_an_empty_list(): void
    {
        Http::fake(['repo.test/*' => Http::response('', 500)]);

        $this->assertSame([], app(PluginCatalog::class)->fetch('https://repo.test/manifest.json'));
    }

    /* -------------------------------------------------------- installer --- */

    public function test_it_installs_a_valid_plugin_disabled(): void
    {
        $zip = $this->makeZip([
            'plugin.json' => json_encode([
                'id' => 'acme.installed', 'name' => 'Installed', 'version' => '1.0.0',
                'entrypoint' => 'Acme\\Installed\\Plugin', 'minSoundChexVersion' => '0.1.0',
            ]),
            'src/Plugin.php' => '<?php namespace Acme\\Installed; class Plugin {}',
        ]);

        Http::fake(['cdn.test/*' => Http::response($zip)]);

        $entry = new CatalogEntry('acme.installed', 'Installed', null, null, [], [
            'version' => '1.0.0',
            'sourceUrl' => 'https://cdn.test/installed.zip',
            'checksum' => 'sha256:'.hash('sha256', $zip),
        ]);

        $id = $this->installer()->install($entry);

        $this->assertSame('acme.installed', $id);
        $this->assertTrue(is_file($this->pluginsDir.'/acme.installed/plugin.json'));
        $this->assertDatabaseHas('installed_plugins', ['plugin_id' => 'acme.installed', 'enabled' => false]);
    }

    public function test_it_refuses_a_checksum_mismatch(): void
    {
        $zip = $this->makeZip(['plugin.json' => '{}']);
        Http::fake(['cdn.test/*' => Http::response($zip)]);

        $entry = new CatalogEntry('acme.tampered', 'Tampered', null, null, [], [
            'version' => '1.0.0',
            'sourceUrl' => 'https://cdn.test/x.zip',
            'checksum' => 'sha256:'.str_repeat('0', 64), // wrong
        ]);

        $this->expectException(PluginInstallException::class);
        $this->expectExceptionMessage('checksum');

        $this->installer()->install($entry);
    }

    public function test_it_refuses_a_checksum_in_an_unknown_algorithm(): void
    {
        // The algorithm name comes from the catalog, which is untrusted network
        // input. hash() raises a ValueError for one PHP does not know, and a
        // ValueError is not a PluginInstallException — so without the guard a
        // typo'd or exotic algorithm escaped the install UI as a 500 rather
        // than a refusal an admin can read.
        $zip = $this->makeZip(['plugin.json' => '{}']);
        Http::fake(['cdn.test/*' => Http::response($zip)]);

        $entry = new CatalogEntry('acme.odd', 'Odd', null, null, [], [
            'version' => '1.0.0',
            'sourceUrl' => 'https://cdn.test/x.zip',
            'checksum' => 'sha-256:'.str_repeat('0', 64),
        ]);

        $this->expectException(PluginInstallException::class);
        $this->expectExceptionMessage('unknown format');

        $this->installer()->install($entry);
    }

    public function test_it_installs_the_shallowest_manifest_not_the_first_one(): void
    {
        // A plugin that ships an example or fixture plugin of its own has two
        // manifests. locateName(FL_NODIR) returns the first in *archive order*,
        // so a zip listing the nested one first installed the example instead
        // of the plugin (S-368). Ordered deliberately here: nested first.
        $zip = $this->makeZip([
            'porter/examples/demo/plugin.json' => json_encode([
                'id' => 'acme.demo',
                'name' => 'Demo',
                'version' => '1.0.0',
                'entrypoint' => 'Acme\\Demo\\Plugin',
            ]),
            'porter/plugin.json' => json_encode([
                'id' => 'acme.porter',
                'name' => 'Porter',
                'version' => '1.0.0',
                'entrypoint' => 'Acme\\Porter\\Plugin',
            ]),
        ]);

        Http::fake(['cdn.test/*' => Http::response($zip)]);

        $entry = new CatalogEntry('acme.porter', 'Porter', null, null, [], [
            'version' => '1.0.0',
            'sourceUrl' => 'https://cdn.test/x.zip',
        ]);

        $this->assertSame('acme.porter', $this->installer()->install($entry));
    }

    public function test_it_refuses_an_archive_that_escapes_its_directory(): void
    {
        // A zip-slip entry: a path that climbs out of the target folder.
        $zip = $this->makeZip([
            'plugin.json' => json_encode([
                'id' => 'acme.evil', 'name' => 'Evil', 'version' => '1.0.0',
                'entrypoint' => 'Acme\\Evil\\Plugin', 'minSoundChexVersion' => '0.1.0',
            ]),
            '../../escaped.php' => '<?php // pwned',
        ]);

        Http::fake(['cdn.test/*' => Http::response($zip)]);

        $entry = new CatalogEntry('acme.evil', 'Evil', null, null, [], [
            'version' => '1.0.0',
            'sourceUrl' => 'https://cdn.test/evil.zip',
            'checksum' => 'sha256:'.hash('sha256', $zip),
        ]);

        try {
            $this->installer()->install($entry);
            $this->fail('A zip-slip archive should have been refused.');
        } catch (PluginInstallException $e) {
            $this->assertStringContainsString('outside its directory', $e->getMessage());
        }

        // Nothing escaped.
        $this->assertFalse(is_file(base_path('storage/framework/testing/escaped.php')));
    }

    public function test_it_refuses_an_incompatible_download(): void
    {
        $zip = $this->makeZip([
            'plugin.json' => json_encode([
                'id' => 'acme.toonew', 'name' => 'Too New', 'version' => '1.0.0',
                'entrypoint' => 'Acme\\TooNew\\Plugin', 'minSoundChexVersion' => '9.9.9',
            ]),
        ]);
        Http::fake(['cdn.test/*' => Http::response($zip)]);

        $entry = new CatalogEntry('acme.toonew', 'Too New', null, null, [], [
            'version' => '1.0.0',
            'sourceUrl' => 'https://cdn.test/x.zip',
            'checksum' => 'sha256:'.hash('sha256', $zip),
        ]);

        $this->expectException(PluginInstallException::class);

        $this->installer()->install($entry);
    }

    /* ----------------------------------------------------------- helpers --- */

    private function installer(): PluginInstaller
    {
        return new PluginInstaller(new PluginLoader($this->app, app(Registry::class)));
    }

    /**
     * @param  array<string, string>  $files  path in zip => contents
     */
    private function makeZip(array $files): string
    {
        $path = tempnam(sys_get_temp_dir(), 'scx-test-zip-').'.zip';
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        foreach ($files as $name => $contents) {
            $zip->addFromString($name, $contents);
        }

        $zip->close();
        $bytes = (string) file_get_contents($path);
        @unlink($path);

        return $bytes;
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
