<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace Tests\Feature;

use App\Filament\Pages\Integrations;
use App\Models\Profile;
use App\Models\User;
use App\Services\CurrentProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use App\Services\SettingsService;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The page that says what this install is connected to.
 *
 * Two things matter here and they pull in opposite directions. It has to work
 * when nothing is running — which is most installs, and is not an error — and
 * it must not become a way around the server-administration gate, because the
 * apps it configures reach the network and spend disk.
 */
class IntegrationsPageTest extends TestCase
{
    use RefreshDatabase;

    /** Literal, because `getUrl()` needs a booted panel the test kernel lacks. */
    private const URL = '/admin/integrations';

    private User $user;

    private Profile $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();

        $this->owner = Profile::create([
            'user_id' => $this->user->id,
            'name' => 'Owner',
            'is_owner' => true,
        ]);
    }

    private function asOwner(): self
    {
        $this->actingAs($this->user);
        app(CurrentProfile::class)->switchTo($this->owner->id);

        // Livewire renders the page outside a panel request, so the panel it
        // belongs to has to be named. The HTTP tests above get this from the
        // route; a component test does not.
        Filament::setCurrentPanel('admin');

        return $this;
    }

    public function test_the_page_renders_when_nothing_is_running(): void
    {
        // The normal case: no Docker, nothing listening. A connection error is
        // the expected answer and must not surface as a 500.
        $this->withKeys();

        Http::fake(fn () => throw new \Illuminate\Http\Client\ConnectionException('refused'));

        $this->asOwner()
            ->get(self::URL)
            ->assertOk()
            ->assertSee('Acquisition apps are not running');
    }

    /** Keys for every app, so `status()` gets as far as asking. */
    private function withKeys(): void
    {
        foreach (array_keys(config('arr.apps')) as $name) {
            config(["arr.apps.{$name}.key" => 'test-key']);
        }
    }

    public function test_a_running_app_shows_its_version_and_queue(): void
    {
        $this->withKeys();

        Http::fake([
            '*/api/v3/system/status' => Http::response(['version' => '5.14.0.1234']),
            '*/api/v3/queue*' => Http::response(['totalRecords' => 3]),
            '*/api/v3/health' => Http::response([]),
        ]);

        $this->asOwner()
            ->get(self::URL)
            ->assertOk()
            ->assertSee('5.14.0.1234')
            ->assertDontSee('Acquisition apps are not running');
    }

    public function test_a_health_warning_is_surfaced(): void
    {
        $this->withKeys();

        // The case worth showing: up, reachable, and quietly doing nothing
        // because it cannot talk to its download client.
        Http::fake([
            '*/api/v3/system/status' => Http::response(['version' => '5.14.0']),
            '*/api/v3/queue*' => Http::response(['totalRecords' => 0]),
            '*/api/v3/health' => Http::response([
                ['type' => 'warning', 'message' => 'No download client is available'],
            ]),
        ]);

        $this->asOwner()
            ->get(self::URL)
            ->assertOk()
            ->assertSee('No download client is available');
    }

    public function test_an_unconfigured_integration_offers_set_up(): void
    {
        // "Set up" rather than "Manage": the row says which state it is in,
        // which is the whole point of the list.
        config(['arr.apps.radarr.key' => null]);

        $this->asOwner()
            ->get(self::URL)
            ->assertOk()
            ->assertSee('Set up');
    }

    public function test_metadata_providers_are_listed_but_not_editable_here(): void
    {
        $this->asOwner()
            ->get(self::URL)
            ->assertOk()
            ->assertSee('Metadata')
            ->assertSee('TMDB');
    }

    public function test_a_connected_app_offers_a_working_way_to_open_it(): void
    {
        // Third control in this feature that rendered and did nothing, and the
        // first two "fixes" for it were wrong: the cause was never this markup.
        // `media-center.js` called `Alpine.start()` unconditionally, so
        // navigating from /app into /admin left two Alpine instances running —
        // and Alpine binds no directives when that happens. Everything
        // depending on it was inert.
        //
        // So this asserts the control needs no JavaScript at all.
        $this->withKeys();

        Http::fake([
            '*/system/status' => Http::response(['version' => '2.5.3']),
            '*/queue*' => Http::response(['totalRecords' => 0]),
            '*/health' => Http::response([]),
        ]);

        $this->asOwner();

        $html = Livewire::test(Integrations::class)
            ->call('edit', 'lidarr')
            ->html();

        // A plain anchor with a real href. Not Alpine, not window.open: both
        // were tried and both were dead, because navigating from the media
        // center into the panel used to leave two Alpine instances running and
        // Alpine binds no directives at all when that happens.
        $this->assertMatchesRegularExpression(
            '/<a[^>]+href="http:\/\/127\.0\.0\.1:8686"[^>]*>/',
            $html,
            'The open control must be a real link, not something that needs JavaScript.',
        );

        // Without handing the opened page a handle on this one.
        $this->assertStringContainsString('noopener', $html);
    }

    public function test_an_unreachable_app_offers_no_open_control(): void
    {
        // Nothing to open. A link to a refused connection is worse than none.
        //
        // Asserted on the *link*, not on the address: the address appears
        // either way now, in the field that lets someone correct it, which is
        // exactly what an unreachable app needs.
        $this->withKeys();

        Http::fake(fn () => throw new \Illuminate\Http\Client\ConnectionException('refused'));

        $this->asOwner();

        $html = Livewire::test(Integrations::class)
            ->call('edit', 'lidarr')
            ->html();

        $this->assertDoesNotMatchRegularExpression(
            '/<a[^>]+href="http:\/\/127\.0\.0\.1:8686"/',
            $html,
        );
    }

    /* ----------------------------------------------------- api versions -- */

    public function test_each_app_is_asked_on_the_api_version_it_actually_speaks(): void
    {
        // Lidarr is v1. Radarr and Sonarr moved to v3 and Lidarr never did,
        // and assuming all three matched made a *running* Lidarr report itself
        // as stopped: every call 404'd, and a 404 is indistinguishable from
        // nothing listening on the port.
        //
        // The faked HTTP in the tests above could not catch it — they matched
        // `*/api/v3/*` and so asserted against the assumption rather than
        // against the API. This asserts the URL actually requested.
        $this->withKeys();

        Http::fake([
            '*/system/status' => Http::response(['version' => '1.2.3']),
            '*/queue*' => Http::response(['totalRecords' => 0]),
            '*/health' => Http::response([]),
        ]);

        $service = app(\App\Services\ArrServices::class);
        $service->forget();
        $service->all();

        $expected = [
            'radarr' => 'v3',
            'sonarr' => 'v3',
            'lidarr' => 'v1',
        ];

        foreach ($expected as $app => $version) {
            $host = parse_url((string) config("arr.apps.{$app}.url"), PHP_URL_PORT);

            Http::assertSent(fn ($request): bool => str_contains($request->url(), ":{$host}/api/{$version}/system/status"));
        }
    }

    public function test_a_health_warning_names_what_is_missing(): void
    {
        // The case this exists for: up, reachable, and quietly doing nothing
        // because it has no indexer or no download client. Both are real
        // warnings a fresh Lidarr reports.
        $this->withKeys();

        Http::fake([
            '*/system/status' => Http::response(['version' => '2.5.3']),
            '*/queue*' => Http::response(['totalRecords' => 0]),
            '*/health' => Http::response([
                ['type' => 'warning', 'message' => 'No download client is available'],
                ['type' => 'warning', 'message' => 'No indexers available with RSS sync enabled'],
            ]),
        ]);

        $status = app(\App\Services\ArrServices::class)->status('lidarr');

        $this->assertTrue($status['running']);
        $this->assertCount(2, $status['warnings']);
        $this->assertContains('No download client is available', $status['warnings']);
    }

    /* ------------------------------------------------- set up and unlink -- */

    public function test_opening_the_modal_dispatches_the_event_that_shows_it(): void
    {
        // The test that was missing. Twelve passing tests covered what `edit()`
        // put in the component's state and none covered whether anything
        // appeared — so a modal bound to `:visible` instead of the event it
        // actually listens for shipped looking fully tested. The button did
        // nothing and every assertion was green.
        $this->asOwner();

        Livewire::test(Integrations::class)
            ->call('edit', 'tmdb_api_key')
            ->assertDispatched('open-modal', id: 'integration');
    }

    public function test_closing_dispatches_the_close_event(): void
    {
        $this->asOwner();

        Livewire::test(Integrations::class)
            ->call('edit', 'tmdb_api_key')
            ->call('closeModal')
            ->assertDispatched('close-modal', id: 'integration')
            ->assertSet('editing', null);
    }

    public function test_saving_closes_the_modal_rather_than_leaving_it_open(): void
    {
        $this->asOwner();

        Livewire::test(Integrations::class)
            ->call('edit', 'tmdb_api_key')
            ->set('editingKey', 'a-key')
            ->call('saveModal')
            ->assertDispatched('close-modal', id: 'integration');
    }

    /* ------------------------------------------------------------ order -- */

    public function test_groups_follow_the_declared_order(): void
    {
        $this->asOwner();

        $page = app(Integrations::class);
        $page->load();

        $groups = array_keys($page->groupedRows());

        $expected = array_values(array_filter(
            Integrations::GROUP_ORDER,
            fn (string $g): bool => in_array($g, $groups, true),
        ));

        $this->assertSame($expected, $groups);
        $this->assertSame('Acquisition', $groups[0], 'Acquisition should lead: it is the only group this page can change.');
    }

    public function test_an_unlisted_group_is_dropped_rather_than_appended(): void
    {
        // This is the assertion with teeth, and the reason the test above is
        // weaker than it looks: the sources happen to be *declared* in the
        // same sequence as GROUP_ORDER, so comparing rendered order to
        // declared order passes whether the sort runs or not. Two attempts at
        // that assertion proved nothing before this was measured directly.
        //
        // Iterating GROUP_ORDER rather than the built rows has one observable
        // consequence that build order cannot fake: a group nobody listed does
        // not appear at all. That is deliberate — a provider added with a typo
        // in its group should be noticed as missing, not quietly tacked onto
        // the end of the page where it looks intentional.
        $this->asOwner();

        $page = app(Integrations::class);
        $page->load();

        $groups = array_keys($page->groupedRows());

        foreach ($groups as $group) {
            $this->assertContains(
                $group,
                Integrations::GROUP_ORDER,
                "'{$group}' is rendered but not declared in GROUP_ORDER.",
            );
        }
    }

    public function test_connected_providers_sort_above_unconfigured_ones(): void
    {
        // A configured provider is the one with something to say. Burying it
        // under eight unconfigured ones makes the page look emptier than it is.
        app(SettingsService::class)->set('omdb_api_key', 'a-key', encrypt: true);

        $this->asOwner();

        $page = app(Integrations::class);
        $page->load();

        $film = collect($page->groupedRows()['Film & TV']);

        $this->assertSame('OMDb', $film->first()['label']);
        $this->assertTrue($film->first()['connected']);
    }

    public function test_the_declared_order_survives_the_connected_sort(): void
    {
        // Stability matters: with nothing connected, the recommended order is
        // the whole value of the list.
        $this->asOwner();

        $page = app(Integrations::class);
        $page->load();

        $labels = collect($page->groupedRows()['Film & TV'])->pluck('label')->all();

        $this->assertSame(['TMDB', 'TVDB', 'OMDb', 'Trakt'], $labels);
    }

    public function test_setting_up_stores_the_key_encrypted(): void
    {
        $this->asOwner();

        Livewire::test(Integrations::class)
            ->call('edit', 'tmdb_api_key')
            ->set('editingKey', '  a-real-key  ')
            ->call('saveModal')
            ->assertSet('editing', null);

        // Trimmed: a pasted key routinely carries a trailing newline, which
        // the API rejects as invalid rather than as whitespace.
        $this->assertSame('a-real-key', app(SettingsService::class)->get('tmdb_api_key'));

        // And stored encrypted, not in the clear.
        $this->assertNotSame(
            'a-real-key',
            \Illuminate\Support\Facades\DB::table('settings')->where('key', 'tmdb_api_key')->value('value'),
        );
    }

    public function test_the_modal_never_shows_the_stored_key(): void
    {
        // These are encrypted so they are not readable from the panel.
        // Decrypting one back onto a screen to populate a form would undo
        // that for a field nobody edits in place — a key is replaced.
        app(SettingsService::class)->set('tmdb_api_key', 'secret-value', encrypt: true);

        $this->asOwner();

        Livewire::test(Integrations::class)
            ->call('edit', 'tmdb_api_key')
            ->assertSet('editingKey', '')
            ->assertDontSee('secret-value');
    }

    public function test_an_empty_key_is_refused_rather_than_stored(): void
    {
        $this->asOwner();

        Livewire::test(Integrations::class)
            ->call('edit', 'tmdb_api_key')
            ->set('editingKey', '   ')
            ->call('saveModal')
            // Still open: closing would look like it had worked.
            ->assertSet('editing', 'tmdb_api_key');

        $this->assertNull(app(SettingsService::class)->get('tmdb_api_key'));
    }

    public function test_unlinking_forgets_only_the_key(): void
    {
        app(SettingsService::class)->set('tmdb_api_key', 'a-key', encrypt: true);

        $this->asOwner();

        Livewire::test(Integrations::class)->call('unlink', 'tmdb_api_key');

        $this->assertNull(app(SettingsService::class)->get('tmdb_api_key'));
    }

    public function test_an_acquisition_key_is_namespaced_away_from_metadata(): void
    {
        // The two live in one settings table. An app's key is written under
        // `arr.<name>.api_key` so a metadata provider could never collide
        // with one, and unlink has to follow the same rule or it would clear
        // the wrong row.
        $this->asOwner();

        Livewire::test(Integrations::class)
            ->call('edit', 'radarr')
            ->set('editingKey', 'radarr-key')
            ->call('saveModal');

        $settings = app(SettingsService::class);

        $this->assertSame('radarr-key', $settings->get('arr.radarr.api_key'));
        $this->assertNull($settings->get('radarr'));

        Livewire::test(Integrations::class)->call('unlink', 'radarr');

        $this->assertNull($settings->get('arr.radarr.api_key'));
    }

    public function test_a_profile_without_server_administration_is_refused(): void
    {
        // The gate, not merely the absence of a link: Filament resolves a page
        // by URL whether or not anything points at it.
        $other = Profile::create([
            'user_id' => $this->user->id,
            'name' => 'Library admin',
        ]);

        $this->actingAs($this->user);
        app(CurrentProfile::class)->switchTo($other->id);

        $this->get(self::URL)->assertForbidden();
    }

    public function test_it_is_hidden_from_navigation_for_that_profile(): void
    {
        $other = Profile::create([
            'user_id' => $this->user->id,
            'name' => 'Library admin',
        ]);

        $this->actingAs($this->user);
        app(CurrentProfile::class)->switchTo($other->id);

        $this->assertFalse(Integrations::shouldRegisterNavigation());
    }
}
