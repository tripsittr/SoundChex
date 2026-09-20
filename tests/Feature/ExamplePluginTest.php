<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace Tests\Feature;

use App\Enums\MediaItemType;
use App\Models\InstalledPlugin;
use App\Models\MediaItem;
use App\Models\User;
use App\Plugins\PluginLoader;
use App\Plugins\Registry;
use App\Services\Metadata\MetadataPipeline;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The bundled Year Tagger example, installed and run for real (S-264 Phase 2).
 *
 * This exercises the whole on-disk path end to end — a real manifest and source
 * under `plugins/examples`, discovered, enabled, autoloaded at runtime, joined
 * to the pipeline, and actually enriching an item — proving metadata sources are
 * a working plugin seam, not just a unit-tested one. It is the pattern S-39's
 * richer sources ship through.
 */
class ExamplePluginTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'soundchex.plugins.path' => base_path('plugins/examples'),
            'soundchex.plugins.enabled' => true,
            'soundchex.version' => '0.1.0',
            'metadata_sources.movie' => [], // isolate: only the plugin runs
        ]);

        $this->app->forgetInstance(Registry::class);
        $this->app->singleton(Registry::class);
    }

    public function test_the_example_plugin_installs_and_fills_a_year_from_the_filename(): void
    {
        // Discover + enable the bundled example, then boot the loader so it
        // registers on the same registry the pipeline reads.
        $loader = new PluginLoader($this->app, app(Registry::class));
        $loader->discover();

        InstalledPlugin::where('plugin_id', 'soundchex.year-tagger')->update(['enabled' => true]);

        $loader->boot();

        $film = MediaItem::create([
            'user_id' => User::factory()->create()->id,
            'type' => MediaItemType::Movie,
            'title' => 'Some Film',
            'file_path' => 'media/library/Movies/Some Film (2018)/Some Film (2018) 1080p.mkv',
            'owned' => true,
        ]);
        $film->movieMetadata()->create([]); // no year yet

        app(MetadataPipeline::class)->run($film->fresh(['movieMetadata']));

        $this->assertSame(2018, $film->movieMetadata->fresh()->release_year);
    }

    public function test_it_leaves_a_year_a_real_provider_already_set(): void
    {
        $loader = new PluginLoader($this->app, app(Registry::class));
        $loader->discover();
        InstalledPlugin::where('plugin_id', 'soundchex.year-tagger')->update(['enabled' => true]);
        $loader->boot();

        $film = MediaItem::create([
            'user_id' => User::factory()->create()->id,
            'type' => MediaItemType::Movie,
            'title' => 'Some Film',
            'file_path' => 'media/library/Movies/Some Film (2018)/Some Film (2018).mkv',
            'owned' => true,
        ]);
        $film->movieMetadata()->create(['release_year' => 1999]); // already known

        app(MetadataPipeline::class)->run($film->fresh(['movieMetadata']));

        $this->assertSame(1999, $film->movieMetadata->fresh()->release_year);
    }
}
