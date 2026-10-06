<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace Tests\Feature;

use App\Filament\Pages\Integrations;
use App\Models\Profile;
use App\Models\User;
use App\Services\CurrentProfile;
use App\Services\Metadata\CredentialTester;
use App\Services\Metadata\SourceCatalogue;
use App\Services\Oauth\OauthProvider;
use App\Services\Oauth\TokenStore;
use App\Services\SettingsService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Integrations that say what they are, and keys that do what they claim (#490).
 *
 * The page hardcoded thirteen metadata fields while `requiredSettings()` — the
 * contract method that exists to drive it — went unread. Seven of those keys
 * turned out to be read by **nothing**, so pasting one turned a card green and
 * changed no behaviour at all.
 *
 * These pin the four things that made that possible:
 *
 *  - the page is derived from what sources declare, not from a second list
 *  - a key nothing reads is *labelled* as such instead of silently collected
 *  - a half-configured source is neither connected nor untouched
 *  - a group the page has not heard of still renders, rather than vanishing
 */
class IntegrationsFoundationTest extends TestCase
{
    use RefreshDatabase;

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
        Filament::setCurrentPanel('admin');

        return $this;
    }

    private function page(): Integrations
    {
        $page = new Integrations;
        $page->apps = [];

        return $page;
    }

    /* ------------------------------------------------- derived, not listed -- */

    public function test_every_field_comes_from_a_source_that_declares_it(): void
    {
        // The invariant the whole change rests on: no key reaches the page
        // without a source asking for it. A second hardcoded list is exactly
        // how seven dead keys got there.
        $declared = app(SourceCatalogue::class)->liveKeys();

        foreach (app(SourceCatalogue::class)->credentialled() as $source) {
            foreach (array_keys($source['keys']) as $key) {
                $this->assertContains(
                    $key,
                    $declared,
                    "The page offers {$key}, which no source declares.",
                );
            }
        }
    }

    public function test_a_source_needing_two_credentials_is_one_card(): void
    {
        // Spotify needs an id and a secret. Two cards would ask somebody to
        // connect the same service twice and leave one half permanently empty.
        $spotify = collect(app(SourceCatalogue::class)->credentialled())
            ->firstWhere('name', 'Spotify');

        $this->assertNotNull($spotify);
        $this->assertCount(2, $spotify['keys']);
    }

    public function test_two_sources_sharing_a_credential_are_one_card(): void
    {
        // The Movie and Show TMDB sources both read `tmdb_api_key`.
        $tmdb = collect(app(SourceCatalogue::class)->credentialled())
            ->where('name', 'TMDB');

        $this->assertCount(1, $tmdb, 'TMDB appeared more than once.');
    }

    public function test_a_bare_list_of_settings_does_not_render_a_field_called_zero(): void
    {
        // The contract documents `key => label`; two sources returned a bare
        // list, which makes the key an integer and the label the key. Both are
        // fixed, but a plugin can still get it wrong and the settings page is a
        // poor place to find out.
        foreach (app(SourceCatalogue::class)->credentialled() as $source) {
            foreach (array_keys($source['keys']) as $key) {
                $this->assertFalse(
                    is_numeric($key),
                    "A numeric settings key ({$key}) means a bare list slipped through.",
                );
            }
        }
    }

    /* --------------------------------------------------------- dead keys --- */

    public function test_a_key_nothing_reads_is_named_as_unimplemented(): void
    {
        // Measured against the live code: these seven are collected by the old
        // page and read by nothing.
        $dead = collect(app(SourceCatalogue::class)->unimplemented())->pluck('key');

        // `omdb_api_key` was on this list and no longer is: OMDb is
        // implemented (#504) and now fills the IMDb rating, the Rotten
        // Tomatoes score, Metacritic and the awards sentence. Six left.
        foreach (['trakt_client_secret', 'discogs_token',
            'genius_api_key', 'musixmatch_api_key', 'google_books_api_key',
            'fanart_tv_api_key'] as $key) {
            $this->assertContains($key, $dead, "{$key} is read by nothing but is not declared unimplemented.");
        }
    }

    public function test_no_key_is_both_live_and_unimplemented(): void
    {
        // The contradiction that would make the page lie in both directions at
        // once -- and the check that catches an entry left behind when a source
        // is finally implemented.
        $live = app(SourceCatalogue::class)->liveKeys();

        foreach (array_keys(SourceCatalogue::UNIMPLEMENTED) as $dead) {
            $this->assertNotContains(
                $dead,
                $live,
                "{$dead} is declared unimplemented but a source reads it — remove it from UNIMPLEMENTED.",
            );
        }
    }

    public function test_a_stored_dead_key_is_surfaced_rather_than_hidden(): void
    {
        // Somebody pasted it and believes it is working. Saying nothing leaves
        // them with a green card over a service that was never called.
        app(SettingsService::class)->set('discogs_token', 'pasted-and-useless', encrypt: true);

        $this->assertTrue($this->page()->hasStoredDeadKeys());

        $discogs = collect($this->page()->unimplementedSources())->firstWhere('key', 'discogs_token');

        $this->assertTrue($discogs['stored']);
    }

    public function test_a_dead_key_can_be_removed(): void
    {
        app(SettingsService::class)->set('genius_api_key', 'useless', encrypt: true);

        $this->asOwner();
        $this->page()->forgetDeadKey('genius_api_key');

        $this->assertNull(app(SettingsService::class)->get('genius_api_key'));
    }

    public function test_removing_a_dead_key_cannot_delete_a_live_one(): void
    {
        // The action takes a key from the page, so it must refuse anything not
        // on the unimplemented list -- otherwise it is a general-purpose
        // "forget any setting" button reachable from a card.
        app(SettingsService::class)->set('tmdb_api_key', 'a-real-key', encrypt: true);

        $this->asOwner();
        $this->page()->forgetDeadKey('tmdb_api_key');

        $this->assertSame('a-real-key', app(SettingsService::class)->get('tmdb_api_key'));
    }

    /* ------------------------------------------------- half-configured ----- */

    public function test_one_of_two_credentials_reads_as_half_set_up(): void
    {
        // The real state of a live install: a Spotify secret, no client id, and
        // a page reporting it connected while `supports()` returned false.
        app(SettingsService::class)->set('spotify.client_secret', 'secret-only', encrypt: true);

        $spotify = collect(app(SourceCatalogue::class)->credentialled())
            ->firstWhere('name', 'Spotify');

        $this->assertFalse($spotify['configured'], 'Half-configured must not read as connected.');
        $this->assertTrue($spotify['partial']);
    }

    public function test_saving_only_one_of_two_credentials_is_refused(): void
    {
        $this->asOwner();

        $page = $this->page();
        $page->edit('spotify.client_id');
        $page->editingKey = 'just-the-id';
        $page->editingSecondKey = '';
        $page->saveModal();

        // Neither stored: a pair saved half-way is the state that produces a
        // green card over a source that cannot authenticate.
        $this->assertNull(app(SettingsService::class)->get('spotify.client_id'));
        $this->assertNull(app(SettingsService::class)->get('spotify.client_secret'));
    }

    public function test_both_credentials_save_together(): void
    {
        $this->asOwner();

        $page = $this->page();
        $page->edit('spotify.client_id');
        $page->editingKey = 'the-id';
        $page->editingSecondKey = 'the-secret';
        $page->saveModal();

        $this->assertSame('the-id', app(SettingsService::class)->get('spotify.client_id'));
        $this->assertSame('the-secret', app(SettingsService::class)->get('spotify.client_secret'));
    }

    public function test_a_blank_field_keeps_the_stored_value(): void
    {
        // Correcting a mistyped secret must not mean re-pasting the client id:
        // neither is readable from the page to copy.
        $settings = app(SettingsService::class);
        $settings->set('spotify.client_id', 'original-id', encrypt: true);
        $settings->set('spotify.client_secret', 'old-secret', encrypt: true);

        $this->asOwner();

        $page = $this->page();
        $page->edit('spotify.client_id');
        $page->editingKey = '';
        $page->editingSecondKey = 'new-secret';
        $page->saveModal();

        $this->assertSame('original-id', $settings->get('spotify.client_id'));
        $this->assertSame('new-secret', $settings->get('spotify.client_secret'));
    }

    public function test_unlinking_forgets_every_credential_the_source_declares(): void
    {
        // Forgetting one of a pair leaves an orphan behind -- which is how this
        // install came to hold a Spotify secret with no client id.
        $settings = app(SettingsService::class);
        $settings->set('spotify.client_id', 'id', encrypt: true);
        $settings->set('spotify.client_secret', 'secret', encrypt: true);

        $this->asOwner();
        $this->page()->unlink('spotify.client_id');

        $this->assertNull($settings->get('spotify.client_id'));
        $this->assertNull($settings->get('spotify.client_secret'));
    }

    /* ------------------------------------------------------- the groups ---- */

    public function test_a_group_the_page_does_not_know_still_renders(): void
    {
        // `groupedRows()` iterated a fixed list of group names, which made that
        // list a filter: OpenSubtitles built a card in a "Subtitles" group that
        // was not on it, and the page silently rendered 12 of 13 rows.
        $page = $this->page();

        $this->assertSame(
            count($page->rows()),
            collect($page->groupedRows())->flatten(1)->count(),
            'A row was built and then dropped because its group was not in GROUP_ORDER.',
        );
    }

    public function test_the_subtitles_source_appears(): void
    {
        $groups = array_keys($this->page()->groupedRows());

        $this->assertContains('Subtitles', $groups);
    }

    public function test_keyless_sources_are_listed_so_the_page_does_not_imply_nothing_works(): void
    {
        // A page made entirely of key fields implies nothing works without
        // one, when the file tagger, MusicBrainz, iTunes, Deezer and Open
        // Library do most of the identifying and need nothing.
        $names = collect(app(SourceCatalogue::class)->keyless())->pluck('name');

        $this->assertContains('MusicBrainz', $names);
        $this->assertContains('Open Library', $names);
    }

    public function test_every_source_is_grouped(): void
    {
        // The "Other" fallback exists so a plugin's source cannot vanish, but a
        // built-in landing there means a name was guessed wrong -- which is
        // exactly how TheTVDB-vs-TVDB was caught.
        foreach ([...app(SourceCatalogue::class)->credentialled(), ...app(SourceCatalogue::class)->keyless()] as $source) {
            $this->assertNotSame(
                'Other',
                $source['group'],
                $source['name'].' is ungrouped — its PROFILES key does not match name().',
            );
        }
    }

    public function test_every_source_says_what_it_adds(): void
    {
        // A field with no explanation is a field nobody fills in, which is the
        // other half of why six keys sat unused.
        foreach ([...app(SourceCatalogue::class)->credentialled(), ...app(SourceCatalogue::class)->keyless()] as $source) {
            $this->assertNotSame('', trim($source['adds']), $source['name'].' explains nothing.');
        }
    }

    /* ------------------------------------------------------- the tester ---- */

    public function test_a_working_key_passes(): void
    {
        Http::fake(['api.themoviedb.org/*' => Http::response(['images' => []], 200)]);

        $result = app(CredentialTester::class)->test('tmdb_api_key', ['tmdb_api_key' => 'good']);

        $this->assertTrue($result['ok']);
    }

    public function test_a_rejected_key_fails_with_the_services_own_words(): void
    {
        // Preferred on purpose: "Invalid API key: You must be granted a valid
        // key" tells somebody what to do and "Request failed (401)" does not.
        Http::fake([
            'api.themoviedb.org/*' => Http::response(['status_message' => 'Invalid API key: You must be granted a valid key.'], 401),
        ]);

        $result = app(CredentialTester::class)->test('tmdb_api_key', ['tmdb_api_key' => 'bad']);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('Invalid API key', $result['message']);
    }

    public function test_an_unreachable_service_is_not_reported_as_a_bad_key(): void
    {
        // On a server behind a proxy the difference decides whether somebody
        // re-requests a key they already have.
        Http::fake(fn () => throw new ConnectionException('refused'));

        $result = app(CredentialTester::class)->test('tmdb_api_key', ['tmdb_api_key' => 'fine']);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('network', $result['message']);
    }

    public function test_acoustid_rejects_a_bad_key_by_error_code(): void
    {
        // Matched on the code, not the message. A substring check for "client"
        // passed a key AcoustID had plainly rejected, because its message reads
        // "invalid API key" and never mentions the client -- verified against
        // the live API.
        Http::fake([
            'api.acoustid.org/*' => Http::response(['error' => ['code' => 4, 'message' => 'invalid API key']], 400),
        ]);

        $result = app(CredentialTester::class)->test('acoustid_api_key', ['acoustid_api_key' => 'bad']);

        $this->assertFalse($result['ok']);
    }

    public function test_acoustid_accepts_a_good_key_despite_the_missing_fingerprint(): void
    {
        // The positive half, which the live API could not prove here for want
        // of a real key: the request is deliberately incomplete, so a complaint
        // about anything *other* than the key means the key was accepted.
        Http::fake([
            'api.acoustid.org/*' => Http::response(['error' => ['code' => 2, 'message' => 'missing required parameter "fingerprint"']], 400),
        ]);

        $result = app(CredentialTester::class)->test('acoustid_api_key', ['acoustid_api_key' => 'good']);

        $this->assertTrue($result['ok']);
    }

    public function test_lastfm_is_judged_on_its_body_not_its_status(): void
    {
        // Last.fm has been known to answer 200 with an error body, so the body
        // decides.
        Http::fake([
            'ws.audioscrobbler.com/*' => Http::response(['error' => 10, 'message' => 'Invalid API key'], 200),
        ]);

        $result = app(CredentialTester::class)->test('lastfm_api_key', ['lastfm_api_key' => 'bad']);

        $this->assertFalse($result['ok']);
    }

    public function test_a_service_that_cannot_be_tested_says_so(): void
    {
        $this->assertFalse(app(CredentialTester::class)->canTest('discogs_token'));
    }

    /* --------------------------------------------------------- the OAuth --- */

    public function test_a_callback_with_no_stored_state_is_refused(): void
    {
        // Accepting one would accept a callback nobody here started, binding
        // somebody else's account to this install.
        //
        // Everything else is made to succeed -- credentials stored, the token
        // endpoint faked to hand back a usable token -- so the *only* reason
        // this can fail is the state check. Asserting on `isConnected()` alone
        // proved nothing: with no credentials the exchange failed anyway, and
        // the test passed with the check deleted outright.
        $this->registerSpotifyApp();
        $this->fakeWorkingTokenEndpoint();

        $this->asOwner()
            ->get('/oauth/spotify/callback?code=stolen&state=attacker-chosen')
            ->assertRedirect();

        $this->assertFalse(
            app(TokenStore::class)->isConnected(OauthProvider::Spotify),
            'A callback with no state we issued was exchanged anyway.',
        );

        // And nothing was even asked of the service.
        Http::assertNothingSent();
    }

    public function test_a_callback_whose_state_does_not_match_is_refused(): void
    {
        $this->registerSpotifyApp();
        $this->fakeWorkingTokenEndpoint();

        $this->asOwner();

        session(['oauth_state.spotify' => 'the-real-one']);

        $this->get('/oauth/spotify/callback?code=c&state=a-different-one');

        $this->assertFalse(
            app(TokenStore::class)->isConnected(OauthProvider::Spotify),
            'A mismatched state was accepted.',
        );

        Http::assertNothingSent();
    }

    public function test_a_state_is_single_use(): void
    {
        // Pulled from the session, not read: a replayed callback must not work
        // twice, or an intercepted URL stays usable.
        $this->registerSpotifyApp();
        $this->fakeWorkingTokenEndpoint();

        $this->asOwner();
        session(['oauth_state.spotify' => 'once']);

        $this->get('/oauth/spotify/callback?code=good&state=once');
        $this->assertTrue(app(TokenStore::class)->isConnected(OauthProvider::Spotify));

        app(TokenStore::class)->forget(OauthProvider::Spotify);

        // The same callback again, with the state now consumed.
        $this->get('/oauth/spotify/callback?code=good&state=once');

        $this->assertFalse(
            app(TokenStore::class)->isConnected(OauthProvider::Spotify),
            'A state was accepted twice, so a replayed callback still works.',
        );
    }

    private function registerSpotifyApp(): void
    {
        $settings = app(SettingsService::class);
        $settings->set('spotify.client_id', 'id', encrypt: true);
        $settings->set('spotify.client_secret', 'secret', encrypt: true);
    }

    private function fakeWorkingTokenEndpoint(): void
    {
        Http::fake([
            'accounts.spotify.com/api/token' => Http::response([
                'access_token' => 'would-have-worked',
                'refresh_token' => 'r',
                'expires_in' => 3600,
            ], 200),
        ]);
    }

    public function test_a_matching_state_exchanges_the_code(): void
    {
        $settings = app(SettingsService::class);
        $settings->set('spotify.client_id', 'id', encrypt: true);
        $settings->set('spotify.client_secret', 'secret', encrypt: true);

        Http::fake([
            'accounts.spotify.com/api/token' => Http::response([
                'access_token' => 'the-token',
                'refresh_token' => 'the-refresh',
                'expires_in' => 3600,
            ], 200),
        ]);

        $this->asOwner();
        session(['oauth_state.spotify' => 'matching']);

        $this->get('/oauth/spotify/callback?code=good&state=matching');

        $this->assertTrue(app(TokenStore::class)->isConnected(OauthProvider::Spotify));
    }

    public function test_signing_in_requires_the_server_administration_permission(): void
    {
        // The token is stored install-wide, so this is a machine decision. A
        // household member who can manage the library has no business binding
        // the server to their own account.
        $other = User::factory()->create();

        $limited = Profile::create([
            'user_id' => $other->id,
            'name' => 'Guest',
            'is_owner' => false,
        ]);

        $this->actingAs($other);
        app(CurrentProfile::class)->switchTo($limited->id);

        $this->get('/oauth/spotify/redirect')->assertForbidden();
    }

    public function test_a_refresh_response_without_a_refresh_token_keeps_the_old_one(): void
    {
        // Services commonly omit it, meaning "keep using the one you have".
        // Overwriting it with nothing signs the user out at the next expiry,
        // hours later, with nothing connecting the two events.
        $this->asOwner();

        $store = app(TokenStore::class);

        $store->store(OauthProvider::Spotify, [
            'access_token' => 'first',
            'refresh_token' => 'keep-me',
            'expires_in' => 3600,
        ]);

        $store->store(OauthProvider::Spotify, [
            'access_token' => 'second',
            'expires_in' => 3600,
        ]);

        Http::fake([
            'accounts.spotify.com/api/token' => Http::response(['access_token' => 'third', 'expires_in' => 3600], 200),
        ]);

        app(SettingsService::class)->set('spotify.client_id', 'id', encrypt: true);
        app(SettingsService::class)->set('spotify.client_secret', 'secret', encrypt: true);

        // Only possible if the refresh token survived the second store().
        $this->assertSame('third', $store->refresh(OauthProvider::Spotify));
    }

    public function test_a_token_about_to_expire_is_refreshed_before_use(): void
    {
        // Without a margin, a token checked at 0:00 and used at 0:04 expires
        // mid-request -- an intermittent 401 that only shows up under load.
        $this->asOwner();

        $settings = app(SettingsService::class);
        $settings->set('spotify.client_id', 'id', encrypt: true);
        $settings->set('spotify.client_secret', 'secret', encrypt: true);

        $store = app(TokenStore::class);
        $store->store(OauthProvider::Spotify, [
            'access_token' => 'nearly-dead',
            'refresh_token' => 'r',
            'expires_in' => 30,
        ]);

        Http::fake([
            'accounts.spotify.com/api/token' => Http::response(['access_token' => 'fresh', 'expires_in' => 3600], 200),
        ]);

        $this->assertSame('fresh', $store->accessToken(OauthProvider::Spotify));
    }

    public function test_a_failed_refresh_does_not_discard_the_tokens(): void
    {
        // A refresh can fail because the network is down. Clearing the tokens
        // over a transient fault turns a retry into a sign-in by hand.
        $this->asOwner();

        $settings = app(SettingsService::class);
        $settings->set('spotify.client_id', 'id', encrypt: true);
        $settings->set('spotify.client_secret', 'secret', encrypt: true);

        $store = app(TokenStore::class);
        $store->store(OauthProvider::Spotify, [
            'access_token' => 'a',
            'refresh_token' => 'r',
            'expires_in' => 10,
        ]);

        Http::fake(['accounts.spotify.com/api/token' => Http::response([], 500)]);

        $store->refresh(OauthProvider::Spotify);

        $this->assertTrue($store->isConnected(OauthProvider::Spotify));
    }

    public function test_a_rejected_grant_does_clear_them(): void
    {
        // `invalid_grant` is the service saying the refresh token is dead.
        // Keeping it means every later call fails identically while the user is
        // never told to sign in again.
        $this->asOwner();

        $settings = app(SettingsService::class);
        $settings->set('spotify.client_id', 'id', encrypt: true);
        $settings->set('spotify.client_secret', 'secret', encrypt: true);

        $store = app(TokenStore::class);
        $store->store(OauthProvider::Spotify, [
            'access_token' => 'a',
            'refresh_token' => 'r',
            'expires_in' => 10,
        ]);

        Http::fake([
            'accounts.spotify.com/api/token' => Http::response(['error' => 'invalid_grant'], 400),
        ]);

        $store->refresh(OauthProvider::Spotify);

        $this->assertFalse($store->isConnected(OauthProvider::Spotify));
    }

    public function test_the_redirect_uri_does_not_move_with_the_request(): void
    {
        // A service matches `redirect_uri` against the one URI registered for
        // the app. `SetAppUrl` rewrites `app.url` per address, so a redirect
        // built from the request differs on the tailnet and the LAN and is
        // rejected on both (S-322).
        config(['app.configured_url' => 'https://registered.example.com']);
        config(['app.url' => 'https://some-other-address.example.com']);

        $this->assertSame(
            'https://registered.example.com/oauth/spotify/callback',
            OauthProvider::Spotify->redirectUri(),
        );
    }

    public function test_an_operator_override_wins_for_the_redirect_uri(): void
    {
        // The Playlist Porter plugin hit this first: when the browser reaches
        // the app on one address and the registered URI is another, only an
        // override reconciles them. One setting serves both so they cannot
        // drift apart.
        config(['app.configured_url' => 'https://ignored.example.com']);

        app(SettingsService::class)->set(OauthProvider::BASE_URL_SETTING, 'https://chosen.example.com/');

        $this->assertSame(
            'https://chosen.example.com/oauth/spotify/callback',
            OauthProvider::Spotify->redirectUri(),
        );
    }

    public function test_spotify_tokens_share_the_plugins_keys(): void
    {
        // Inventing a second `oauth.spotify.*` set would mean one sign-in the
        // import flow could not see and another this could not -- the same
        // split that left the install holding two different client secrets
        // under two spellings.
        $this->asOwner();

        app(TokenStore::class)->store(OauthProvider::Spotify, [
            'access_token' => 'shared',
            'expires_in' => 3600,
        ]);

        $this->assertSame(
            'shared',
            app(SettingsService::class)->get('spotify.user.'.$this->user->id.'.access'),
        );
    }

    public function test_the_expiry_is_stored_as_a_timestamp_the_plugin_can_read(): void
    {
        // Sharing a key means sharing its format. An ISO string here reads back
        // as 0 in the plugin, which would refresh on every single call.
        $this->asOwner();

        app(TokenStore::class)->store(OauthProvider::Spotify, [
            'access_token' => 'a',
            'expires_in' => 3600,
        ]);

        $stored = (string) app(SettingsService::class)->get('spotify.user.'.$this->user->id.'.expires');

        $this->assertTrue(ctype_digit($stored), "Expiry was stored as '{$stored}', which the plugin cannot read.");
    }
}
