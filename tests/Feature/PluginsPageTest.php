<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace Tests\Feature;

use App\Filament\Pages\Plugins;
use App\Models\InstalledPlugin;
use App\Models\Profile;
use App\Models\User;
use App\Services\CurrentProfile;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The admin Plugins manager (S-264 Phase 4): it lists installed plugins,
 * discovers the bundled example from disk, and toggles enabled state — the
 * deliberate act that runs a plugin's code.
 */
class PluginsPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $user = User::factory()->create();
        $owner = Profile::create(['user_id' => $user->id, 'name' => 'Owner', 'is_owner' => true]);

        $this->actingAs($user);
        app(CurrentProfile::class)->switchTo($owner->id);
        Filament::setCurrentPanel('admin');

        // Point discovery at the bundled example so the page has real data.
        config(['soundchex.plugins.path' => base_path('plugins/examples')]);
    }

    public function test_it_discovers_and_lists_the_bundled_example(): void
    {
        Livewire::test(Plugins::class)
            ->assertSee('Year Tagger')
            ->assertSee('Disabled'); // arrives disabled

        $this->assertDatabaseHas('installed_plugins', [
            'plugin_id' => 'soundchex.year-tagger',
            'enabled' => false,
        ]);
    }

    public function test_enabling_a_plugin_flips_its_state(): void
    {
        Livewire::test(Plugins::class)
            ->call('rescan')
            ->call('toggle', 'soundchex.year-tagger')
            ->assertHasNoErrors();

        $this->assertTrue(
            (bool) InstalledPlugin::where('plugin_id', 'soundchex.year-tagger')->value('enabled'),
        );
    }

    public function test_disabling_a_plugin_flips_it_back(): void
    {
        Livewire::test(Plugins::class)->call('rescan');
        InstalledPlugin::where('plugin_id', 'soundchex.year-tagger')->update(['enabled' => true]);

        Livewire::test(Plugins::class)
            ->call('toggle', 'soundchex.year-tagger')
            ->assertHasNoErrors();

        $this->assertFalse(
            (bool) InstalledPlugin::where('plugin_id', 'soundchex.year-tagger')->value('enabled'),
        );
    }

    public function test_it_shows_an_empty_state_when_nothing_is_installed(): void
    {
        config(['soundchex.plugins.path' => base_path('tests/Fixtures/does-not-exist')]);

        Livewire::test(Plugins::class)
            ->assertSee('No plugins installed');
    }
}
