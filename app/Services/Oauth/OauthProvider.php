<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Services\Oauth;

use App\Services\SettingsService;

/**
 * One service a user signs in to, rather than pastes a key for (#490).
 *
 * Some integrations cannot be authorised by a credential at all. Spotify's
 * client-credentials grant reads public catalogue data but cannot see a user's
 * own playlists; Trakt's whole purpose is a particular person's watch history.
 * For those, a pasted secret is necessary and not sufficient — somebody has to
 * be sent to the service and come back with a token.
 *
 * The app had none of this: no callback route, no token store, no refresh. A
 * key field for Trakt was therefore a field that could never work, which is
 * why it sat among the seven dead keys.
 *
 * Each case names the two settings holding the app's own registration and the
 * scopes to ask for. The tokens themselves are stored per service by
 * `TokenStore`, encrypted, never in config.
 */
enum OauthProvider: string
{
    case Spotify = 'spotify';
    case Trakt = 'trakt';

    public function label(): string
    {
        return match ($this) {
            self::Spotify => 'Spotify',
            self::Trakt => 'Trakt',
        };
    }

    /**
     * The settings key holding the app's client id.
     *
     * Spotify's is `spotify.client_id`, **not** `spotify_client_id`. The
     * Playlist Porter plugin registered an app under the dotted keys first and
     * holds a working connection on them; the Integrations page invented the
     * underscored pair separately, and this install ended up with a
     * `spotify_client_secret` that is not even the same value as the one in
     * use. Two key names for one credential means filling in one does nothing,
     * so the dotted pair -- the one with a live token against it -- wins and
     * the other is migrated away.
     */
    public function clientIdKey(): string
    {
        return match ($this) {
            self::Spotify => 'spotify.client_id',
            self::Trakt => 'trakt.client_id',
        };
    }

    /** The settings key holding the app's client secret. */
    public function clientSecretKey(): string
    {
        return match ($this) {
            self::Spotify => 'spotify.client_secret',
            self::Trakt => 'trakt.client_secret',
        };
    }

    public function authorizeUrl(): string
    {
        return match ($this) {
            self::Spotify => 'https://accounts.spotify.com/authorize',
            self::Trakt => 'https://api.trakt.tv/oauth/authorize',
        };
    }

    public function tokenUrl(): string
    {
        return match ($this) {
            self::Spotify => 'https://accounts.spotify.com/api/token',
            self::Trakt => 'https://api.trakt.tv/oauth/token',
        };
    }

    /**
     * The permissions to ask for.
     *
     * Deliberately narrow. Asking for playlist-modify when nothing writes
     * playlists trains people to approve prompts they have not read, and a
     * token this app holds is a token this app can lose.
     *
     * @return array<int, string>
     */
    public function scopes(): array
    {
        return match ($this) {
            self::Spotify => [
                'user-read-private',
                'user-read-email',
                'playlist-read-private',
                'playlist-read-collaborative',
                'user-library-read',
            ],
            // Trakt scopes everything to the one token; there is nothing to
            // narrow, which is worth saying rather than leaving blank.
            self::Trakt => [],
        };
    }

    /** What signing in adds, for the card. */
    public function adds(): string
    {
        return match ($this) {
            self::Spotify => 'Your own playlists and saved albums, for importing and matching against the library.',
            self::Trakt => 'Your watch history, and scrobbling what you play here back to Trakt.',
        };
    }

    /**
     * The setting holding the base URL services return to.
     *
     * Shared with the Playlist Porter plugin, which hit this first (S-322) and
     * learned something worth inheriting: `APP_URL` alone is not enough. This
     * server is deliberately reachable several ways at once -- a tailnet host
     * on :8443, a public Funnel on :443, loopback :8000 for the desktop shell
     * -- and a service matches `redirect_uri` against the single URI registered
     * for the app, character for character. When the browser reaches the app on
     * one address and the registered URI is another, only an operator override
     * can reconcile them, so one setting serves both and they cannot drift.
     */
    public const BASE_URL_SETTING = 'playlist_porter.oauth_base_url';

    /**
     * Where this provider sends the user back to.
     *
     * Never built from the current request. `SetAppUrl` rewrites `app.url` to
     * whichever address the request arrived on, which is right for assets and
     * wrong here -- it silently produces a different `redirect_uri` per access
     * path, and every path but the registered one is rejected with
     * "redirect_uri: Not matching configuration".
     */
    public function redirectUri(): string
    {
        return $this->baseUrl().'/oauth/'.$this->value.'/callback';
    }

    /** The operator's override, else the server's configured address. */
    public function baseUrl(): string
    {
        $override = trim((string) app(SettingsService::class)->get(self::BASE_URL_SETTING, ''));

        if ($override !== '') {
            return rtrim($override, '/');
        }

        // `configured_url`, not `app.url`: the latter is rewritten per request.
        // Trailing slashes trimmed, because the services compare exact strings
        // and a doubled slash is a mismatch.
        return rtrim((string) (config('app.configured_url') ?: config('app.url')), '/');
    }

    public static function tryFromSlug(string $slug): ?self
    {
        return self::tryFrom(strtolower(trim($slug)));
    }
}
