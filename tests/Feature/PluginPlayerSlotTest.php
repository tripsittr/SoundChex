<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace Tests\Feature;

use App\Enums\MediaItemType;
use App\Models\MediaItem;
use App\Models\User;
use App\Plugins\Registry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

/**
 * Plugins writing into the user-facing player (S-318).
 *
 * The admin panel takes its positions from Filament. The media center has no
 * such system, so its slots are placed by hand in the Blade — each one a
 * decision about where a plugin may write, rather than an accident of whatever
 * markup happened to be wrapped.
 */
class PluginPlayerSlotTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_slot_renders_what_a_plugin_contributes(): void
    {
        app(Registry::class)->forPlugin('acme.demo', function (Registry $r): void {
            $r->slot('player.controls', fn (): string => '<button>cast</button>');
        });

        $this->assertSame(
            '<button>cast</button>',
            Blade::render("@pluginSlot('player.controls')"),
        );
    }

    public function test_an_empty_slot_renders_nothing(): void
    {
        // Every install is in this state until a plugin is enabled, so the
        // slot must cost nothing and add no stray markup.
        $this->assertSame('', Blade::render("@pluginSlot('player.controls')"));
    }

    public function test_slots_render_in_registration_order(): void
    {
        app(Registry::class)->forPlugin('acme.first', function (Registry $r): void {
            $r->slot('player.meta', fn (): string => 'A');
        });

        app(Registry::class)->forPlugin('acme.second', function (Registry $r): void {
            $r->slot('player.meta', fn (): string => 'B');
        });

        $this->assertSame('AB', Blade::render("@pluginSlot('player.meta')"));
    }

    public function test_a_slot_receives_its_context(): void
    {
        $item = $this->track();

        app(Registry::class)->forPlugin('acme.demo', function (Registry $r): void {
            $r->slot('song.row.actions', fn (MediaItem $i): string => "id:{$i->id}");
        });

        $this->assertSame(
            "id:{$item->id}",
            app(Registry::class)->renderSlot('song.row.actions', $item),
        );
    }

    public function test_a_plugin_that_throws_contributes_nothing_and_does_not_break_the_page(): void
    {
        app(Registry::class)->forPlugin('acme.broken', function (Registry $r): void {
            $r->slot('player.meta', function (): string {
                throw new \RuntimeException('plugin exploded');
            });
        });

        app(Registry::class)->forPlugin('acme.fine', function (Registry $r): void {
            $r->slot('player.meta', fn (): string => 'still here');
        });

        // The working plugin's markup survives its neighbour's failure.
        $this->assertSame('still here', Blade::render("@pluginSlot('player.meta')"));
    }

    public function test_the_now_playing_bar_carries_its_slots(): void
    {
        app(Registry::class)->forPlugin('acme.demo', function (Registry $r): void {
            $r->slot('player.controls', fn (): string => '<!--CTRL-->');
            $r->slot('player.meta', fn (): string => '<!--META-->');
        });

        $html = view('components.media.now-playing')->render();

        $this->assertStringContainsString('<!--CTRL-->', $html);
        $this->assertStringContainsString('<!--META-->', $html);
        $this->assertStringContainsString('np-title', $html, 'the bar itself is untouched');
    }

    public function test_hasSlot_reports_whether_anything_is_registered(): void
    {
        $registry = app(Registry::class);

        $this->assertFalse($registry->hasSlot('album.detail'));

        $registry->forPlugin('acme.demo', function (Registry $r): void {
            $r->slot('album.detail', fn (): string => 'x');
        });

        $this->assertTrue($registry->hasSlot('album.detail'));
    }

    private function track(): MediaItem
    {
        $item = MediaItem::create([
            'user_id' => User::factory()->create()->id,
            'type' => MediaItemType::Music,
            'title' => 'A Song',
            'file_path' => 'media/a-song.mp3',
            'owned' => true,
        ]);

        $item->musicMetadata()->create(['artist' => 'An Artist']);

        return $item->fresh();
    }
}
