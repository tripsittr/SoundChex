<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace SoundChex\PlaylistPorter\Services\Sources;

use App\Services\SettingsService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use SoundChex\PlaylistPorter\Services\ImportedTrack;

/**
 * Imports playlists from Spotify (S-312).
 *
 * Authorization Code flow: the operator registers a Spotify app once (client id
 * and secret, stored in settings), and each user authorises their own account.
 * Spotify's playlist tracks carry an ISRC and the Spotify track id, so a ported
 * Spotify playlist matches the library decisively rather than by fuzzy text.
 *
 * Tokens are stored per user (access + refresh) and refreshed transparently when
 * the access token has expired.
 */
class SpotifySource implements PlaylistSource
{
    private const AUTH_URL = 'https://accounts.spotify.com/authorize';

    private const TOKEN_URL = 'https://accounts.spotify.com/api/token';

    private const API = 'https://api.spotify.com/v1';

    /** The scopes needed to read a user's playlists. */
    private const SCOPES = 'playlist-read-private playlist-read-collaborative';

    public function __construct(private SettingsService $settings) {}

    public function key(): string
    {
        return 'spotify';
    }

    public function name(): string
    {
        return 'Spotify';
    }

    public function isConfigured(): bool
    {
        return filled($this->clientId()) && filled($this->clientSecret());
    }

    public function isConnected(): bool
    {
        return filled($this->settings->get($this->tokenKey('refresh')));
    }

    public function authorizationUrl(string $redirectUri, string $state): string
    {
        return self::AUTH_URL.'?'.http_build_query([
            'client_id' => $this->clientId(),
            'response_type' => 'code',
            'redirect_uri' => $redirectUri,
            'scope' => self::SCOPES,
            'state' => $state,
        ]);
    }

    public function connect(string $code, string $redirectUri): void
    {
        $response = Http::asForm()->post(self::TOKEN_URL, [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $redirectUri,
            'client_id' => $this->clientId(),
            'client_secret' => $this->clientSecret(),
        ]);

        $response->throw();
        $this->storeTokens($response->json());
    }

    public function disconnect(): void
    {
        $this->settings->forget($this->tokenKey('access'));
        $this->settings->forget($this->tokenKey('refresh'));
        $this->settings->forget($this->tokenKey('expires'));
    }

    public function playlists(): array
    {
        $out = [];
        $url = self::API.'/me/playlists?limit=50';

        // Spotify paginates with a `next` url; follow it to the end.
        while ($url !== null) {
            $body = $this->get($url);

            foreach ($body['items'] ?? [] as $playlist) {
                $out[] = [
                    'id' => (string) ($playlist['id'] ?? ''),
                    'name' => (string) ($playlist['name'] ?? 'Untitled'),
                    'track_count' => $playlist['tracks']['total'] ?? null,
                ];
            }

            $url = $body['next'] ?? null;
        }

        return $out;
    }

    public function fetch(string $playlistId): array
    {
        $meta = $this->get(self::API."/playlists/{$playlistId}?fields=name");
        $name = (string) ($meta['name'] ?? 'Spotify playlist');

        $tracks = [];
        $url = self::API."/playlists/{$playlistId}/tracks?limit=100&fields=next,items(track(name,duration_ms,external_ids(isrc),id,artists(name),album(name)))";

        while ($url !== null) {
            $body = $this->get($url);

            foreach ($body['items'] ?? [] as $row) {
                $track = $row['track'] ?? null;

                if (! is_array($track) || blank($track['name'] ?? null)) {
                    continue; // a removed/unavailable track
                }

                $tracks[] = new ImportedTrack(
                    title: $track['name'],
                    artist: $this->primaryArtist($track['artists'] ?? []),
                    album: $track['album']['name'] ?? null,
                    isrc: $track['external_ids']['isrc'] ?? null,
                    spotifyId: $track['id'] ?? null,
                    durationMs: isset($track['duration_ms']) ? (int) $track['duration_ms'] : null,
                    sourceLabel: $track['name'],
                );
            }

            $url = $body['next'] ?? null;
        }

        return ['name' => $name, 'tracks' => $tracks];
    }

    /**
     * A GET against the Spotify API with the current access token, refreshing it
     * once and retrying if the token has expired.
     */
    private function get(string $url): array
    {
        $response = Http::withToken($this->accessToken())->get($url);

        if ($response->status() === 401) {
            $this->refresh();
            $response = Http::withToken($this->accessToken())->get($url);
        }

        $response->throw();

        return (array) $response->json();
    }

    /** A valid access token, refreshing when the stored one has expired. */
    private function accessToken(): ?string
    {
        $expires = (int) $this->settings->get($this->tokenKey('expires'), 0);

        if ($expires > 0 && $expires <= now()->timestamp) {
            $this->refresh();
        }

        return $this->settings->get($this->tokenKey('access'));
    }

    private function refresh(): void
    {
        $refresh = $this->settings->get($this->tokenKey('refresh'));

        if (blank($refresh)) {
            return;
        }

        $response = Http::asForm()->post(self::TOKEN_URL, [
            'grant_type' => 'refresh_token',
            'refresh_token' => $refresh,
            'client_id' => $this->clientId(),
            'client_secret' => $this->clientSecret(),
        ]);

        $response->throw();
        $this->storeTokens($response->json());
    }

    /**
     * @param  array<string, mixed>  $tokens
     */
    private function storeTokens(array $tokens): void
    {
        if (filled($tokens['access_token'] ?? null)) {
            $this->settings->set($this->tokenKey('access'), $tokens['access_token'], encrypt: true);
        }

        // A refresh token is only returned on the first exchange; keep the old
        // one when a refresh response omits it.
        if (filled($tokens['refresh_token'] ?? null)) {
            $this->settings->set($this->tokenKey('refresh'), $tokens['refresh_token'], encrypt: true);
        }

        if (isset($tokens['expires_in'])) {
            $this->settings->set($this->tokenKey('expires'), now()->timestamp + (int) $tokens['expires_in']);
        }
    }

    /** @param array<int, array<string, mixed>> $artists */
    private function primaryArtist(array $artists): ?string
    {
        $names = array_filter(array_map(fn ($a) => $a['name'] ?? null, $artists));

        return $names === [] ? null : implode(', ', $names);
    }

    private function clientId(): ?string
    {
        return $this->settings->get('spotify.client_id');
    }

    private function clientSecret(): ?string
    {
        return $this->settings->get('spotify.client_secret');
    }

    /** Per-user token keys, so each account's Spotify connection is its own. */
    private function tokenKey(string $part): string
    {
        return 'spotify.user.'.Auth::id().'.'.$part;
    }
}
