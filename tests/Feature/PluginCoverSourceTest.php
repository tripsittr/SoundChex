<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace Tests\Feature;

use App\Plugins\Contracts\CoverSource;
use App\Plugins\Registry;
use App\Services\Metadata\CoverArtFetcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * A plugin-contributed cover source is consulted by the fetcher as a fallback,
 * after the built-in iTunes/Deezer lookups (S-264, #280).
 */
class PluginCoverSourceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->forgetInstance(Registry::class);
        $this->app->singleton(Registry::class);

        // The built-ins find nothing, so the plugin source is what answers.
        Http::fake([
            'itunes.apple.com/*' => Http::response(['resultCount' => 0, 'results' => []]),
            'api.deezer.com/*' => Http::response(['data' => []]),
            // The image the plugin source points at, for the download+store step.
            'plugin.test/*' => Http::response('IMAGEBYTES', 200, ['Content-Type' => 'image/jpeg']),
        ]);
    }

    public function test_a_plugin_cover_source_is_tried_as_a_fallback(): void
    {
        app(Registry::class)->forPlugin('acme.covers', function (Registry $r): void {
            $r->coverSource(FakeCoverSource::class, 50);
        });

        $path = app(CoverArtFetcher::class)->fetchForAlbum('Some Artist', 'Some Album');

        // The plugin source supplied a URL, which the fetcher downloaded + stored.
        $this->assertNotNull($path);
    }

    public function test_a_throwing_cover_source_is_skipped_not_fatal(): void
    {
        app(Registry::class)->forPlugin('acme.covers', function (Registry $r): void {
            $r->coverSource(ExplodingCoverSource::class, 10); // tried first, throws
            $r->coverSource(FakeCoverSource::class, 20);       // still reached
        });

        $path = app(CoverArtFetcher::class)->fetchForAlbum('Some Artist', 'Some Album');

        $this->assertNotNull($path);
    }

    public function test_no_plugin_source_leaves_the_result_null(): void
    {
        $path = app(CoverArtFetcher::class)->fetchForAlbum('Some Artist', 'Some Album');

        $this->assertNull($path);
    }
}

class FakeCoverSource implements CoverSource
{
    public function name(): string
    {
        return 'Fake Covers';
    }

    public function priority(): int
    {
        return 50;
    }

    public function coverUrlFor(string $artist, ?string $album): ?string
    {
        return 'https://plugin.test/cover.jpg';
    }
}

class ExplodingCoverSource implements CoverSource
{
    public function name(): string
    {
        return 'Exploding';
    }

    public function priority(): int
    {
        return 10;
    }

    public function coverUrlFor(string $artist, ?string $album): ?string
    {
        throw new \RuntimeException('boom');
    }
}
