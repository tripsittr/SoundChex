<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace Tests\Feature;

use App\Enums\MediaItemType;
use App\Models\MediaItem;
use App\Models\User;
use App\Plugins\PluginLoader;
use App\Plugins\Registry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Bundled first-party plugins (S-264, #279): they load enabled, without an
 * install-table row, and the app's own Title Tidier is now one of them.
 */
class BundledPluginTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->forgetInstance(Registry::class);
        $this->app->singleton(Registry::class);
    }

    public function test_a_bundled_plugin_loads_without_being_installed(): void
    {
        $loader = new PluginLoader($this->app, app(Registry::class));
        $loader->boot();

        // The bundled Title Tidier registered a metadata.title filter, and it did
        // so with no installed_plugins row — bundled plugins are always on.
        $this->assertTrue($loader->registry()->hasFilters('metadata.title'));
        $this->assertDatabaseMissing('installed_plugins', ['plugin_id' => 'soundchex.title-tidier']);
    }

    public function test_the_title_tidier_plugin_strips_a_tracks_artist(): void
    {
        $loader = new PluginLoader($this->app, app(Registry::class));
        $loader->boot();

        $item = MediaItem::create([
            'user_id' => User::factory()->create()->id,
            'type' => MediaItemType::Music,
            'title' => 'Gold - Imagine Dragons',
            'owned' => true,
        ]);
        $item->musicMetadata()->create(['artist' => 'Imagine Dragons']);

        $result = $loader->registry()->apply('metadata.title', 'Gold - Imagine Dragons', $item->fresh(['musicMetadata']));

        $this->assertSame('Gold', $result);
    }

    public function test_the_master_switch_turns_off_bundled_plugins_too(): void
    {
        config(['soundchex.plugins.enabled' => false]);

        $loader = new PluginLoader($this->app, app(Registry::class));
        $loader->boot();

        $this->assertFalse($loader->registry()->hasFilters('metadata.title'));
    }
}
