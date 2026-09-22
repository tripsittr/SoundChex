<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace Tests\Feature\PlaylistPorter;

use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use SoundChex\PlaylistPorter\Services\OAuthRedirect;
use Tests\TestCase;

/**
 * The OAuth redirect URI must not follow the request host (S-322).
 *
 * `SetAppUrl` rebases generated URLs onto whichever address a request arrived
 * on, and this server answers on several at once. A redirect_uri that moved
 * with the request matched the one registered in the Spotify app from only one
 * of them; every other way in was rejected with "Not matching configuration".
 */
class OAuthRedirectTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The plugin is loaded at runtime from the install directory, so its classes
     * are not in the app's autoloader during a test run. Register the one class
     * under test the same way the loader does.
     */
    protected function setUp(): void
    {
        parent::setUp();

        if (! class_exists(OAuthRedirect::class)) {
            $file = $this->pluginRoot().'/src/Services/OAuthRedirect.php';

            if (! is_file($file)) {
                $this->markTestSkipped('Playlist Porter plugin source not available.');
            }

            require_once $file;
        }

        // The plugin's routes are registered at runtime too; the callback route
        // has to exist for a URI to be built from it.
        if (! \Illuminate\Support\Facades\Route::has('playlist-porter.oauth.callback')) {
            require $this->pluginRoot().'/routes/web.php';
            \Illuminate\Support\Facades\Route::getRoutes()->refreshNameLookups();
        }
    }

    /** Where this plugin's source lives, relative to the app repo. */
    private function pluginRoot(): string
    {
        return dirname(base_path()).'/Plugins/soundchex-playlist-porter';
    }

    public function test_redirect_uri_is_the_same_whatever_host_the_request_arrived_on(): void
    {
        $redirects = app(OAuthRedirect::class);
        $expected = $redirects->for('spotify');

        // Stand in for SetAppUrl having rebased everything onto another address.
        config(['app.url' => 'http://127.0.0.1:8000']);
        \Illuminate\Support\Facades\URL::forceRootUrl('http://127.0.0.1:8000');

        $this->assertSame($expected, app(OAuthRedirect::class)->for('spotify'));
    }

    public function test_it_survives_set_app_url_running_first(): void
    {
        // Exactly what happens on a real request: the middleware stashes the
        // configured URL, then rebases app.url onto the request's own host.
        $middleware = new \App\Http\Middleware\SetAppUrl;
        $request = \Illuminate\Http\Request::create('http://127.0.0.1:8000/admin/import-playlist');
        $middleware->handle($request, fn () => new \Illuminate\Http\Response);

        $this->assertSame(
            rtrim((string) config(\App\Http\Middleware\SetAppUrl::CONFIGURED_URL), '/')
                .'/playlist-porter/oauth/spotify/callback',
            app(OAuthRedirect::class)->for('spotify'),
        );
        $this->assertStringNotContainsString('127.0.0.1', app(OAuthRedirect::class)->for('spotify'));
    }

    public function test_operator_can_pin_the_base_url(): void
    {
        app(SettingsService::class)->set(OAuthRedirect::BASE_SETTING, 'https://music.example.com/');

        $this->assertSame(
            'https://music.example.com/playlist-porter/oauth/spotify/callback',
            app(OAuthRedirect::class)->for('spotify'),
        );
    }

    public function test_configured_base_url_does_not_double_its_slash(): void
    {
        app(SettingsService::class)->set(OAuthRedirect::BASE_SETTING, 'https://music.example.com///');

        $this->assertStringNotContainsString(
            'com//playlist-porter',
            app(OAuthRedirect::class)->for('spotify'),
        );
    }
}
