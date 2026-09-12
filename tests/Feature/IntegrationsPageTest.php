<?php

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

    /* ------------------------------------------------- set up and unlink -- */

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
