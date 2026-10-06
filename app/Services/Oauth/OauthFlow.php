<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Services\Oauth;

use App\Services\SettingsService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Sends a user to a service and brings a token back (#490).
 *
 * The authorization-code half of OAuth 2, which is the only half that can speak
 * for a particular person. The `state` parameter is not optional here: without
 * it, anybody can hand the admin a crafted callback URL and have this server
 * exchange *their* code, binding someone else's account to the install.
 */
class OauthFlow
{
    public function __construct(
        private SettingsService $settings,
        private TokenStore $tokens,
    ) {}

    /** Whether the app's own registration has been entered yet. */
    public function isRegistered(OauthProvider $provider): bool
    {
        return filled($this->settings->get($provider->clientIdKey()))
            && filled($this->settings->get($provider->clientSecretKey()));
    }

    /**
     * Where to send the user, and the state to remember.
     *
     * The state is returned rather than stored here so the caller puts it in
     * the session — this service has no business reaching into session state,
     * and a token store that did would be untestable.
     *
     * @return array{url: string, state: string}|null
     */
    public function authorizeUrl(OauthProvider $provider): ?array
    {
        if (! $this->isRegistered($provider)) {
            return null;
        }

        $state = Str::random(40);

        $query = [
            'response_type' => 'code',
            'client_id' => (string) $this->settings->get($provider->clientIdKey()),
            'redirect_uri' => $provider->redirectUri(),
            'state' => $state,
        ];

        if ($provider->scopes() !== []) {
            $query['scope'] = implode(' ', $provider->scopes());
        }

        return [
            'url' => $provider->authorizeUrl().'?'.http_build_query($query),
            'state' => $state,
        ];
    }

    /**
     * Exchanges the code the service sent back for a token.
     *
     * Returns a message rather than throwing: this runs in a browser redirect,
     * and the useful outcome is a sentence on the integrations page saying what
     * happened.
     *
     * @return array{ok: bool, message: string}
     */
    public function exchange(OauthProvider $provider, string $code): array
    {
        if (! $this->isRegistered($provider)) {
            return ['ok' => false, 'message' => 'Enter the client id and secret first.'];
        }

        try {
            $response = Http::asForm()->timeout(20)->post($provider->tokenUrl(), [
                'grant_type' => 'authorization_code',
                'code' => $code,
                'redirect_uri' => $provider->redirectUri(),
                'client_id' => (string) $this->settings->get($provider->clientIdKey()),
                'client_secret' => (string) $this->settings->get($provider->clientSecretKey()),
            ]);
        } catch (\Throwable $e) {
            Log::warning('An OAuth code exchange could not reach the service', [
                'provider' => $provider->value,
                'error' => $e->getMessage(),
            ]);

            return ['ok' => false, 'message' => 'Could not reach '.$provider->label().'.'];
        }

        if (! $response->successful() || blank($response->json('access_token'))) {
            Log::warning('An OAuth code exchange was refused', [
                'provider' => $provider->value,
                'status' => $response->status(),
                // The description, never the body: a token response body holds
                // credentials and must not reach the log.
                'error' => $response->json('error_description') ?? $response->json('error'),
            ]);

            return [
                'ok' => false,
                'message' => (string) ($response->json('error_description')
                    ?? 'The sign-in was refused. Check the redirect URI registered with '.$provider->label().'.'),
            ];
        }

        $this->tokens->store($provider, (array) $response->json());

        return ['ok' => true, 'message' => $provider->label().' is connected.'];
    }

    /**
     * The redirect URI to register with the service, for the setup text.
     *
     * Shown on the page because getting it wrong is the single most common way
     * an OAuth setup fails, and the error the service returns says only that it
     * did not match.
     */
    public function redirectUri(OauthProvider $provider): string
    {
        return $provider->redirectUri();
    }
}
