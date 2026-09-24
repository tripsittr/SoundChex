<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace Tests\Feature;

use App\Enums\MediaItemType;
use App\Models\MediaItem;
use App\Models\User;
use App\Plugins\Registry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\TestCase;

/**
 * Values a plugin can change on their way through the app (S-317).
 *
 * The filter machinery already existed but only one value was ever passed
 * through it, so a plugin could customise almost nothing without rendering.
 * These are the points worth opening: what a row says under its title, what
 * every client reads, and what a search answers.
 *
 * Each also asserts the *unfiltered* result, because a hook that changes a
 * value when nobody asked is a bug, not a feature.
 */
class PluginValueHookTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_plugin_can_rewrite_the_line_under_a_title(): void
    {
        $item = $this->track();

        $this->assertSame('An Artist', $item->subtitle(), 'unfiltered, the artist stands');

        app(Registry::class)->forPlugin('acme.demo', function (Registry $r): void {
            $r->filter('item.subtitle', fn (?string $s): string => strtoupper((string) $s));
        });

        $this->assertSame('AN ARTIST', $item->fresh()->subtitle());
    }

    public function test_a_plugin_can_add_a_key_every_client_sees(): void
    {
        $item = $this->track();

        $before = (new \App\Http\Resources\MediaItemResource($item))->toArray(request());
        $this->assertArrayNotHasKey('acme_flag', $before);

        app(Registry::class)->forPlugin('acme.demo', function (Registry $r): void {
            $r->filter('api.item', fn (array $payload): array => $payload + ['acme_flag' => true]);
        });

        $after = (new \App\Http\Resources\MediaItemResource($item->fresh()))->toArray(request());

        $this->assertTrue($after['acme_flag']);
        $this->assertSame($before['id'], $after['id'], 'the existing shape is untouched');
        $this->assertSame($before['title'], $after['title']);
    }

    public function test_a_plugin_can_reshape_search_results(): void
    {
        $kept = $this->track('Keep Me');
        $dropped = $this->track('Drop Me');

        app(Registry::class)->forPlugin('acme.demo', function (Registry $r): void {
            $r->filter(
                'search.results',
                fn (Collection $items): Collection => $items->reject(
                    fn (MediaItem $i): bool => str_contains($i->title, 'Drop'),
                )->values(),
            );
        });

        $filtered = app(Registry::class)->apply(
            'search.results',
            collect([$kept, $dropped]),
            'me',
        );

        $this->assertCount(1, $filtered);
        $this->assertSame('Keep Me', $filtered->first()->title);
    }

    public function test_a_filter_that_throws_does_not_break_the_value(): void
    {
        $item = $this->track();

        app(Registry::class)->forPlugin('acme.broken', function (Registry $r): void {
            $r->filter('item.subtitle', function (): string {
                throw new \RuntimeException('plugin exploded');
            });
        });

        // The original value survives: one bad plugin must not blank a field
        // for everyone.
        $this->assertSame('An Artist', $item->fresh()->subtitle());
    }

    private function track(string $title = 'A Song'): MediaItem
    {
        $item = MediaItem::create([
            'user_id' => User::factory()->create()->id,
            'type' => MediaItemType::Music,
            'title' => $title,
            'file_path' => 'media/'.md5($title).'.mp3',
            'owned' => true,
        ]);

        $item->musicMetadata()->create(['artist' => 'An Artist']);

        return $item->fresh();
    }
}
