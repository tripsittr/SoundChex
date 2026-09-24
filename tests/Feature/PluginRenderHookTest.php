<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace Tests\Feature;

use App\Plugins\Registry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Plugins putting their own UI into the admin panel (S-316).
 *
 * A plugin can already contribute a whole page. These are the smaller seams:
 * markup at a named position in the panel's chrome, and a dashboard widget —
 * the difference between a plugin that lives in its own corner and one that
 * belongs to the product.
 */
class PluginRenderHookTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_plugin_can_register_markup_at_a_named_position(): void
    {
        $registry = new Registry;

        $registry->forPlugin('acme.demo', function (Registry $r): void {
            $r->renderHook('panels::page.start', fn (): string => '<p>acme</p>');
        });

        $hooks = $registry->renderHooks();

        $this->assertCount(1, $hooks);
        $this->assertSame('acme.demo', $hooks[0]['plugin'], 'the hook is attributed to its plugin');
        $this->assertSame('panels::page.start', $hooks[0]['hook']);
        $this->assertSame('<p>acme</p>', ($hooks[0]['callback'])());
    }

    public function test_hooks_keep_their_registration_order(): void
    {
        // Two plugins at the same position render in the order they loaded, so
        // the result is at least predictable rather than arbitrary.
        $registry = new Registry;

        $registry->forPlugin('acme.first', function (Registry $r): void {
            $r->renderHook('panels::page.start', fn (): string => 'first');
        });

        $registry->forPlugin('acme.second', function (Registry $r): void {
            $r->renderHook('panels::page.start', fn (): string => 'second');
        });

        $this->assertSame(
            ['acme.first', 'acme.second'],
            array_column($registry->renderHooks(), 'plugin'),
        );
    }

    public function test_a_plugin_can_contribute_a_dashboard_widget(): void
    {
        $registry = new Registry;

        $registry->forPlugin('acme.demo', function (Registry $r): void {
            $r->widget(\App\Filament\Widgets\AccountWidget::class);
        });

        $this->assertSame(
            [\App\Filament\Widgets\AccountWidget::class],
            $registry->widgetClasses(),
        );
    }

    public function test_an_unregistered_hook_yields_nothing(): void
    {
        $this->assertSame([], (new Registry)->renderHooks());
        $this->assertSame([], (new Registry)->widgetClasses());
    }

    public function test_the_admin_panel_still_renders_with_no_plugin_hooks(): void
    {
        // The seam must cost nothing when nothing uses it — this is the state
        // every install is in until a plugin is enabled.
        $user = \App\Models\User::factory()->create();
        $owner = \App\Models\Profile::create([
            'user_id' => $user->id,
            'name' => 'Owner',
            'is_owner' => true,
        ]);

        $this->actingAs($user);
        app(\App\Services\CurrentProfile::class)->switchTo($owner->id);

        $this->get('/admin')->assertOk();
    }
}
