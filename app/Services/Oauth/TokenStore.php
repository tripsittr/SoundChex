<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Services\Oauth;

use App\Services\SettingsService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Holds the tokens a signed-in service hands back, and keeps them alive (#490).
 *
 * Three settings per provider: the access token, the refresh token and when the
 * access token dies. All three go through `SettingsService` encrypted, because
 * an access token is a bearer credential — anybody holding it is the user as
 * far as the service is concerned, which makes it worth more than the API key
 * next to it.
 *
 * The expiry is stored as an absolute timestamp rather than the `expires_in`
 * seconds the service returns. Seconds are only meaningful next to the moment
 * they were issued, and that moment is exactly what is lost by the time anybody
 * reads the value back.
 */
class TokenStore
{
    /**
     * Refreshed this long before it actually expires.
     *
     * A token checked at 0:00 and used at 0:04 is a token that expired
     * mid-request. The margin costs one early refresh and removes a whole class
     * of intermittent 401 that only appears under load.
     */
    private const REFRESH_MARGIN_SECONDS = 120;

    public function __construct(private SettingsService $settings) {}

    /** Whether the user has signed this service in. */
    public function isConnected(OauthProvider $provider): bool
    {
        return filled($this->settings->get($this->key($provider, 'access_token')));
    }

    /**
     * A usable access token, refreshed if it is about to expire.
     *
     * Returns null rather than throwing: a stale integration should degrade to
     * "this source contributed nothing" and not take a library scan down with
     * it.
     */
    public function accessToken(OauthProvider $provider): ?string
    {
        $token = $this->settings->get($this->key($provider, 'access_token'));

        if (blank($token)) {
            return null;
        }

        if (! $this->expiresSoon($provider)) {
            return (string) $token;
        }

        return $this->refresh($provider);
    }

    /**
     * Records what the service handed back after a sign-in or a refresh.
     *
     * A refresh response often omits `refresh_token`, meaning "keep using the
     * one you have". Overwriting it with null there would sign the user out
     * silently at the next expiry — hours later, with nothing connecting the
     * two events.
     *
     * @param  array<string, mixed>  $payload
     */
    public function store(OauthProvider $provider, array $payload): void
    {
        $access = $payload['access_token'] ?? null;

        if (blank($access)) {
            return;
        }

        $this->settings->set($this->key($provider, 'access_token'), (string) $access, encrypt: true);

        if (filled($payload['refresh_token'] ?? null)) {
            $this->settings->set($this->key($provider, 'refresh_token'), (string) $payload['refresh_token'], encrypt: true);
        }

        $lifetime = (int) ($payload['expires_in'] ?? 0);

        $this->settings->set(
            $this->key($provider, 'expires_at'),
            // A unix timestamp, which is what the Playlist Porter plugin writes
            // to the same key. Sharing a key means sharing its format: an ISO
            // string here would read back as 0 there and refresh on every
            // single call, and the plugin's timestamp would be unparseable
            // here. Absolute either way -- `expires_in` is relative to an issue
            // time that nothing records.
            $lifetime > 0
                ? (string) CarbonImmutable::now()->addSeconds($lifetime)->getTimestamp()
                : '',
        );
    }

    /**
     * Exchanges a refresh token for a new access token.
     *
     * Returns null on failure and clears nothing. A refresh can fail because
     * the network is down, and discarding the tokens over a transient fault
     * would turn a retry into a re-authorisation the user has to do by hand.
     * Only an explicit rejection of the grant clears them.
     */
    public function refresh(OauthProvider $provider): ?string
    {
        $refreshToken = $this->settings->get($this->key($provider, 'refresh_token'));

        if (blank($refreshToken)) {
            return null;
        }

        $clientId = (string) $this->settings->get($provider->clientIdKey());
        $clientSecret = (string) $this->settings->get($provider->clientSecretKey());

        if ($clientId === '' || $clientSecret === '') {
            return null;
        }

        try {
            $response = Http::asForm()->timeout(15)->post($provider->tokenUrl(), [
                'grant_type' => 'refresh_token',
                'refresh_token' => (string) $refreshToken,
                'client_id' => $clientId,
                'client_secret' => $clientSecret,
            ]);
        } catch (\Throwable $e) {
            Log::warning('An OAuth refresh could not reach the service', [
                'provider' => $provider->value,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        if (! $response->successful()) {
            // `invalid_grant` is the service saying the refresh token is dead
            // -- revoked, or the password changed. That is the one case where
            // keeping it is worse than clearing it, because every later call
            // will fail the same way and the user needs to be told to sign in
            // again rather than watch it fail quietly.
            if ($response->json('error') === 'invalid_grant') {
                Log::warning('An OAuth grant was rejected; the user must sign in again', [
                    'provider' => $provider->value,
                ]);

                $this->forget($provider);
            } else {
                Log::warning('An OAuth refresh failed', [
                    'provider' => $provider->value,
                    'status' => $response->status(),
                ]);
            }

            return null;
        }

        $this->store($provider, (array) $response->json());

        return (string) $this->settings->get($this->key($provider, 'access_token'));
    }

    /** Signs the service out, locally. */
    public function forget(OauthProvider $provider): void
    {
        foreach (['access_token', 'refresh_token', 'expires_at'] as $part) {
            $this->settings->forget($this->key($provider, $part));
        }
    }

    /** When the current access token expires, if it is known. */
    public function expiresAt(OauthProvider $provider): ?CarbonImmutable
    {
        $raw = $this->settings->get($this->key($provider, 'expires_at'));

        if (blank($raw)) {
            return null;
        }

        // A bare integer is the shared unix-timestamp form; anything else is
        // parsed as a date so a value written by an older build still reads.
        if (ctype_digit(trim((string) $raw))) {
            return CarbonImmutable::createFromTimestamp((int) trim((string) $raw));
        }

        try {
            return CarbonImmutable::parse((string) $raw);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Whether the token needs refreshing before it is used.
     *
     * An unknown expiry counts as "soon". A provider that returned no
     * `expires_in` might issue tokens that never expire, but assuming so means
     * a 401 nobody can explain; one wasted refresh is the cheaper mistake.
     */
    private function expiresSoon(OauthProvider $provider): bool
    {
        $expiresAt = $this->expiresAt($provider);

        if ($expiresAt === null) {
            return true;
        }

        return $expiresAt->subSeconds(self::REFRESH_MARGIN_SECONDS)->isPast();
    }

    /**
     * Where a provider's tokens live.
     *
     * Spotify's are the Playlist Porter plugin's per-user keys, because the
     * plugin got there first and holds a live connection on them. Inventing a
     * second `oauth.spotify.*` set would mean one Spotify sign-in that the
     * import flow could not see and another that this could not -- the same
     * split that already left this install holding two different client
     * secrets under two spellings.
     *
     * Per-user for Spotify and server-wide for anything else, which is the
     * plugin's own distinction and the right one: a playlist belongs to a
     * person, while a metadata credential belongs to the install.
     */
    private function key(OauthProvider $provider, string $part): string
    {
        if ($provider === OauthProvider::Spotify) {
            return 'spotify.user.'.(Auth::id() ?? 0).'.'.match ($part) {
                'access_token' => 'access',
                'refresh_token' => 'refresh',
                'expires_at' => 'expires',
                default => $part,
            };
        }

        return "oauth.{$provider->value}.{$part}";
    }
}
