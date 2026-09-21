<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace Tests\Feature;

use App\Models\InstalledPlugin;
use App\Plugins\PluginLoader;
use App\Plugins\Registry;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The Title Case example plugin (S-264): a configurable `metadata.title` filter.
 * It doubles as a test that a bundled plugin can read a setting to change its
 * behaviour, and that its filter runs through the registry.
 */
class TitleCasePluginTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'soundchex.plugins.path' => base_path('plugins/examples'),
            'soundchex.plugins.enabled' => true,
            'soundchex.version' => '0.1.0',
        ]);

        $this->app->forgetInstance(Registry::class);
        $this->app->singleton(Registry::class);
    }

    #[DataProvider('styles')]
    public function test_it_applies_the_configured_style(string $style, string $input, string $expected): void
    {
        app(SettingsService::class)->set('title_case_style', $style);

        $this->enableTitleCase();

        $result = app(Registry::class)->apply('metadata.title', $input);

        $this->assertSame($expected, $result);
    }

    public static function styles(): array
    {
        return [
            'title keeps minor words low' => ['title', 'the lord OF the rings', 'The Lord of the Rings'],
            'title leads with a capital' => ['title', 'a tale of two cities', 'A Tale of Two Cities'],
            'sentence' => ['sentence', 'THE QUICK BROWN FOX', 'The quick brown fox'],
            'upper' => ['upper', 'Quiet Storm', 'QUIET STORM'],
            'lower' => ['lower', 'Quiet Storm', 'quiet storm'],
            'start caps every word' => ['start', 'the lord of the rings', 'The Lord Of The Rings'],
        ];
    }

    public function test_it_defaults_to_title_case_with_no_setting(): void
    {
        $this->enableTitleCase();

        $this->assertSame(
            'Blood on the Tracks',
            app(Registry::class)->apply('metadata.title', 'blood on the tracks'),
        );
    }

    public function test_it_is_inert_until_enabled(): void
    {
        // Not enabled: the filter is never registered, so a title passes through.
        app(SettingsService::class)->set('title_case_style', 'upper');

        $loader = new PluginLoader($this->app, app(Registry::class));
        $loader->discover(); // discovered but left disabled
        $loader->boot();

        $this->assertSame('untouched', app(Registry::class)->apply('metadata.title', 'untouched'));
    }

    private function enableTitleCase(): void
    {
        $loader = new PluginLoader($this->app, app(Registry::class));
        $loader->discover();
        InstalledPlugin::where('plugin_id', 'soundchex.title-case')->update(['enabled' => true]);
        $loader->boot();
    }
}
