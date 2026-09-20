<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace Tests\Feature;

use App\Enums\MediaItemType;
use App\Models\MediaItem;
use App\Models\User;
use App\Plugins\Registry;
use App\Services\Metadata\Contracts\MetadataSource;
use App\Services\Metadata\MetadataPipeline;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A plugin-contributed metadata source runs in the pipeline exactly like a
 * built-in one (S-264 Phase 2) — the first real, dogfooded extension point, and
 * the mechanism the extra sources of S-39 will ship through as plugins.
 */
class PluginMetadataSourceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // A clean registry, and only the built-in music sources in config, so
        // the test measures what the plugin adds rather than the whole library.
        $this->app->forgetInstance(Registry::class);
        $this->app->singleton(Registry::class);
        config(['metadata_sources.music' => []]);
    }

    public function test_a_plugin_source_joins_the_pipeline_for_its_type(): void
    {
        app(Registry::class)->forPlugin('acme.demo', function (Registry $r): void {
            $r->metadataSource('music', PluginTestSource::class, 10);
        });

        $sources = app(MetadataPipeline::class)->sourcesFor($this->track());

        $this->assertCount(1, $sources);
        $this->assertInstanceOf(PluginTestSource::class, $sources[0]);
    }

    public function test_a_plugin_source_is_ordered_by_its_own_priority(): void
    {
        config(['metadata_sources.music' => [LatePluginSource::class]]); // priority 90

        app(Registry::class)->forPlugin('acme.demo', function (Registry $r): void {
            $r->metadataSource('music', PluginTestSource::class); // priority 10
        });

        $sources = app(MetadataPipeline::class)->sourcesFor($this->track());

        // The lower priority runs first, regardless of which is a plugin.
        $this->assertInstanceOf(PluginTestSource::class, $sources[0]);
        $this->assertInstanceOf(LatePluginSource::class, $sources[1]);
    }

    public function test_a_plugin_source_only_runs_for_the_type_it_registered(): void
    {
        app(Registry::class)->forPlugin('acme.demo', function (Registry $r): void {
            $r->metadataSource('music', PluginTestSource::class);
        });

        $film = MediaItem::create([
            'user_id' => User::factory()->create()->id,
            'type' => MediaItemType::Movie,
            'title' => 'A Film',
            'owned' => true,
        ]);

        $this->assertSame([], app(MetadataPipeline::class)->sourcesFor($film));
    }

    private function track(): MediaItem
    {
        $item = MediaItem::create([
            'user_id' => User::factory()->create()->id,
            'type' => MediaItemType::Music,
            'title' => 'A Track',
            'owned' => true,
        ]);

        $item->musicMetadata()->create(['artist' => 'An Artist']);

        return $item;
    }
}

/* ------------------------------------------------- plugin source doubles --- */

class PluginTestSource implements MetadataSource
{
    public function supports(MediaItem $item): bool
    {
        return $item->type === MediaItemType::Music;
    }

    public function enrich(MediaItem $item): void {}

    public function priority(): int
    {
        return 10;
    }

    public function name(): string
    {
        return 'Plugin Test Source';
    }

    public function requiredSettings(): array
    {
        return [];
    }
}

class LatePluginSource implements MetadataSource
{
    public function supports(MediaItem $item): bool
    {
        return $item->type === MediaItemType::Music;
    }

    public function enrich(MediaItem $item): void {}

    public function priority(): int
    {
        return 90;
    }

    public function name(): string
    {
        return 'Late Plugin Source';
    }

    public function requiredSettings(): array
    {
        return [];
    }
}
