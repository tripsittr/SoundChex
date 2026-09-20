<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace Tests\Feature;

use App\Filament\Pages\Plugins;
use App\Models\InstalledPlugin;
use App\Models\PluginRepository;
use App\Models\Profile;
use App\Models\User;
use App\Services\CurrentProfile;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
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

    /* ---------------------------------------------------------- catalog --- */

    public function test_browsing_lists_a_repositorys_installable_plugins(): void
    {
        config(['soundchex.version' => '0.2.0']);

        PluginRepository::create(['name' => 'Official', 'url' => 'https://repo.test/manifest.json', 'official' => true]);

        Http::fake(['repo.test/*' => Http::response([[
            'id' => 'acme.catalog', 'name' => 'From Catalogue', 'description' => 'A demo',
            'versions' => [['version' => '1.0.0', 'sourceUrl' => 'https://cdn.test/x.zip', 'targetAbi' => '0.1.0']],
        ]])]);

        Livewire::test(Plugins::class)
            ->call('browse')
            ->assertSet('catalogLoaded', true)
            ->assertSee('From Catalogue');
    }

    public function test_adding_a_repository_records_it(): void
    {
        Http::fake(['*' => Http::response([])]);

        Livewire::test(Plugins::class)
            ->set('newRepositoryUrl', 'https://community.test/plugins.json')
            ->call('addRepository')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('plugin_repositories', ['url' => 'https://community.test/plugins.json']);
    }

    public function test_an_invalid_repository_url_is_rejected(): void
    {
        Livewire::test(Plugins::class)
            ->set('newRepositoryUrl', 'not a url')
            ->call('addRepository');

        $this->assertDatabaseCount('plugin_repositories', 0);
    }

    public function test_an_already_installed_plugin_is_not_offered_in_the_catalogue(): void
    {
        config(['soundchex.version' => '0.2.0']);
        InstalledPlugin::create(['plugin_id' => 'acme.catalog', 'name' => 'X', 'version' => '1.0.0', 'directory' => 'x']);
        PluginRepository::create(['name' => 'Official', 'url' => 'https://repo.test/manifest.json', 'official' => true]);

        Http::fake(['repo.test/*' => Http::response([[
            'id' => 'acme.catalog', 'name' => 'Already Here',
            'versions' => [['version' => '1.0.0', 'sourceUrl' => 'x', 'targetAbi' => '0.1.0']],
        ]])]);

        Livewire::test(Plugins::class)
            ->call('browse')
            ->assertDontSee('Already Here');
    }
}
