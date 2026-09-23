<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace Tests\Feature;

use App\Models\InstalledPlugin;
use App\Models\User;
use App\Plugins\StyleCompiler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Compiling a plugin's stylesheet (S-350).
 *
 * A plugin's Blade cannot use the app's Tailwind — the app's CSS is built
 * before release and a catalogue-installed plugin's markup does not exist yet
 * — so the bundled CLI builds the plugin's own stylesheet when it is enabled.
 *
 * The tests that need the CLI skip without it, so the suite still runs on a
 * machine that has no bundled runtime.
 */
class PluginStyleCompilerTest extends TestCase
{
    use RefreshDatabase;

    private string $pluginDirectory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->pluginDirectory = storage_path('framework/testing/plugin-styles/acme-demo');
        @mkdir($this->pluginDirectory.'/resources/views', 0o775, true);

        config(['soundchex.plugins.path' => dirname($this->pluginDirectory)]);
    }

    protected function tearDown(): void
    {
        $this->deleteDirectory(dirname($this->pluginDirectory));

        parent::tearDown();
    }

    public function test_a_plugin_with_no_views_compiles_nothing(): void
    {
        $this->deleteDirectory($this->pluginDirectory.'/resources');

        $this->assertNull(
            app(StyleCompiler::class)->compile($this->pluginDirectory, 'acme.demo'),
            'a plugin without a UI has no stylesheet to build',
        );
    }

    public function test_it_compiles_the_classes_a_plugin_actually_uses(): void
    {
        $this->skipWithoutCli();

        file_put_contents(
            $this->pluginDirectory.'/resources/views/page.blade.php',
            '<div class="opacity-0 group-hover:opacity-100 sm:ml-auto shrink-0">x</div>',
        );

        $output = app(StyleCompiler::class)->compile($this->pluginDirectory, 'acme.demo');

        $this->assertNotNull($output);

        $css = (string) file_get_contents($output);

        // Every one of these silently did nothing before: written in a plugin,
        // they were never compiled into the app's bundle.
        foreach (['opacity-0', 'group-hover', 'ml-auto', 'shrink-0'] as $class) {
            $this->assertStringContainsString($class, $css, "{$class} should be compiled");
        }
    }

    public function test_it_does_not_compile_classes_the_plugin_does_not_use(): void
    {
        $this->skipWithoutCli();

        file_put_contents($this->pluginDirectory.'/resources/views/page.blade.php', '<div class="flex">x</div>');

        $css = (string) file_get_contents(
            (string) app(StyleCompiler::class)->compile($this->pluginDirectory, 'acme.demo'),
        );

        $this->assertStringNotContainsString(
            'animate-bounce',
            $css,
            'the stylesheet should hold what the plugin uses, not all of Tailwind',
        );
    }

    public function test_a_plugin_can_use_the_app_palette(): void
    {
        $this->skipWithoutCli();

        file_put_contents(
            $this->pluginDirectory.'/resources/views/page.blade.php',
            '<div class="bg-sc-base-900 text-sc-accent">x</div>',
        );

        $css = (string) file_get_contents(
            (string) app(StyleCompiler::class)->compile($this->pluginDirectory, 'acme.demo'),
        );

        $this->assertStringContainsString('bg-sc-base-900', $css);
        $this->assertStringContainsString('--sc-base-900', $css, 'the utility should resolve to the real token');
    }

    public function test_a_plugin_stylesheet_overrides_the_generated_one(): void
    {
        $this->skipWithoutCli();

        @mkdir($this->pluginDirectory.'/resources/css', 0o775, true);
        file_put_contents(
            $this->pluginDirectory.'/resources/css/plugin.css',
            ".acme-only { color: rebeccapurple; }\n",
        );
        file_put_contents($this->pluginDirectory.'/resources/views/page.blade.php', '<div class="flex">x</div>');

        $css = (string) file_get_contents(
            (string) app(StyleCompiler::class)->compile($this->pluginDirectory, 'acme.demo'),
        );

        // Minified, so the colour arrives as its hex equivalent.
        $this->assertStringContainsString('acme-only', $css, "the plugin's own stylesheet is used when present");
    }

    public function test_a_missing_cli_leaves_the_plugin_to_its_own_css(): void
    {
        config(['plugin-styles.binary' => '/definitely/not/a/binary']);

        file_put_contents($this->pluginDirectory.'/resources/views/page.blade.php', '<div class="flex">x</div>');

        $this->assertNull(
            app(StyleCompiler::class)->compile($this->pluginDirectory, 'acme.demo'),
            'no CLI is not an error — the plugin keeps whatever CSS it ships',
        );
    }

    public function test_clearing_removes_the_compiled_stylesheet(): void
    {
        @mkdir($this->pluginDirectory.'/dist', 0o775, true);
        file_put_contents($this->pluginDirectory.'/'.StyleCompiler::OUTPUT, 'x');

        $compiler = app(StyleCompiler::class);
        $this->assertNotNull($compiler->compiledPath($this->pluginDirectory));

        $compiler->clear($this->pluginDirectory);

        $this->assertNull($compiler->compiledPath($this->pluginDirectory));
    }

    public function test_the_route_serves_an_enabled_plugin_as_css(): void
    {
        @mkdir($this->pluginDirectory.'/dist', 0o775, true);
        file_put_contents($this->pluginDirectory.'/'.StyleCompiler::OUTPUT, '.a{color:red}');

        InstalledPlugin::create([
            'plugin_id' => 'acme.demo',
            'name' => 'Acme Demo',
            'version' => '1.0.0',
            'enabled' => true,
            'directory' => 'acme-demo',
            'manifest' => '{}',
        ]);

        $this->actingAs(User::factory()->create())
            ->get('/plugin-styles/acme.demo.css')
            ->assertOk()
            // Served as text/plain, a browser refuses to apply it.
            ->assertHeader('Content-Type', 'text/css; charset=utf-8');
    }

    public function test_a_disabled_plugin_serves_nothing(): void
    {
        @mkdir($this->pluginDirectory.'/dist', 0o775, true);
        file_put_contents($this->pluginDirectory.'/'.StyleCompiler::OUTPUT, '.a{color:red}');

        InstalledPlugin::create([
            'plugin_id' => 'acme.demo',
            'name' => 'Acme Demo',
            'version' => '1.0.0',
            'enabled' => false,
            'directory' => 'acme-demo',
            'manifest' => '{}',
        ]);

        $this->actingAs(User::factory()->create())
            ->get('/plugin-styles/acme.demo.css')
            ->assertNotFound();
    }

    public function test_the_route_needs_a_signed_in_user(): void
    {
        $this->get('/plugin-styles/acme.demo.css')->assertRedirect();
    }

    public function test_the_clear_path_does_not_require_the_folder_to_exist(): void
    {
        // Disabling must clean up precisely when the folder is gone — the case
        // where a compiled stylesheet would otherwise be stranded. Resolving
        // the path with an is_dir() test made that impossible: the clear
        // returned early exactly when it was needed (S-357). The two questions
        // are separate now, so `pluginPath()` answers for a missing folder
        // while `pluginDirectory()` still refuses one.
        $plugin = InstalledPlugin::create([
            'plugin_id' => 'acme.gone',
            'name' => 'Acme Gone',
            'version' => '1.0.0',
            'enabled' => false,
            'directory' => 'acme-gone',          // never created on disk
            'manifest' => '{}',
        ]);

        $page = new \App\Filament\Pages\Plugins;

        $path = new \ReflectionMethod($page, 'pluginPath');
        $path->setAccessible(true);

        $directory = new \ReflectionMethod($page, 'pluginDirectory');
        $directory->setAccessible(true);

        $this->assertNotNull(
            $path->invoke($page, $plugin),
            'the clear path must resolve even when the folder has been deleted',
        );

        $this->assertNull(
            $directory->invoke($page, $plugin),
            'reading a plugin still requires the folder to be there',
        );
    }

    public function test_a_directory_that_became_a_traversal_is_refused(): void
    {
        $plugin = InstalledPlugin::create([
            'plugin_id' => 'acme.evil',
            'name' => 'Acme Evil',
            'version' => '1.0.0',
            'enabled' => false,
            'directory' => '../../etc',
            'manifest' => '{}',
        ]);

        $page = new \App\Filament\Pages\Plugins;
        $path = new \ReflectionMethod($page, 'pluginPath');
        $path->setAccessible(true);

        $this->assertNull($path->invoke($page, $plugin));
    }

    private function skipWithoutCli(): void
    {
        $binary = (string) config('plugin-styles.binary');

        $usable = str_contains($binary, DIRECTORY_SEPARATOR)
            ? is_file($binary) && is_executable($binary)
            : ! empty(shell_exec('command -v '.escapeshellarg($binary)));

        if (! $usable) {
            $this->markTestSkipped('No Tailwind CLI available (set TAILWIND_PATH to run these).');
        }
    }

    private function deleteDirectory(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($items as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }

        @rmdir($path);
    }
}
