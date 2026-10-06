<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Services\Metadata;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Checks a credential against the service it belongs to, at paste time (#490).
 *
 * Without this, a wrong key is indistinguishable from a working one until the
 * next scan enriches nothing and says nothing about why. The user sees a green
 * "Connected" card and an unchanged library, which is the least debuggable
 * failure this app has.
 *
 * Deliberately the cheapest authenticated call each API offers, and never one
 * that writes. The question is only "does this credential work", so a search
 * for a well-known title is enough and a rate-limited endpoint is avoided.
 *
 * A key being tested is passed in rather than read from settings: the point is
 * to check it **before** storing it, so a bad paste never becomes a stored
 * credential that looks fine.
 */
class CredentialTester
{
    /** Short: this runs while somebody waits with a modal open. */
    private const TIMEOUT = 10;

    /**
     * Whether this service can be tested at all.
     *
     * Honest about the ones that cannot. Spotify's client-credentials grant is
     * testable; an OAuth sign-in is not, because there is nothing to test until
     * the user has been through the redirect.
     */
    public function canTest(string $key): bool
    {
        return in_array($key, [
            'tmdb_api_key',
            'tvdb_api_key',
            'lastfm_api_key',
            'acoustid_api_key',
            'opensubtitles_api_key',
            'spotify.client_id',
            'omdb_api_key',
        ], true);
    }

    /**
     * Tests a credential.
     *
     * @param  array<string, string>  $values  Every key the source declared, as typed.
     * @return array{ok: bool, message: string}
     */
    public function test(string $key, array $values): array
    {
        $value = trim($values[$key] ?? '');

        if ($value === '') {
            return $this->fail('Nothing to test — paste the key first.');
        }

        try {
            return match ($key) {
                'tmdb_api_key' => $this->tmdb($value),
                'tvdb_api_key' => $this->tvdb($value),
                'lastfm_api_key' => $this->lastfm($value),
                'acoustid_api_key' => $this->acoustid($value),
                'opensubtitles_api_key' => $this->openSubtitles($value),
                'spotify.client_id' => $this->spotify($value, trim($values['spotify.client_secret'] ?? '')),
                'omdb_api_key' => $this->omdb($value),
                default => $this->fail('This one cannot be tested from here.'),
            };
        } catch (\Throwable $e) {
            // A network fault is not a bad key, and saying so matters: on a
            // server behind a proxy the difference decides whether somebody
            // re-requests a key they already have.
            Log::warning('A credential test could not reach the service', [
                'setting' => $key,
                'error' => $e->getMessage(),
            ]);

            return $this->fail('Could not reach the service. The key may be fine — check this server\'s network.');
        }
    }

    private function tmdb(string $key): array
    {
        $response = Http::timeout(self::TIMEOUT)
            ->get('https://api.themoviedb.org/3/configuration', ['api_key' => $key]);

        // TMDB answers 401 with its own explanation, which is more use than
        // ours -- it distinguishes a malformed key from a revoked one.
        return $response->successful()
            ? $this->pass('Working.')
            : $this->fail($this->reason($response->json('status_message'), $response->status()));
    }

    private function tvdb(string $key): array
    {
        // v4 exchanges the key for a token; a 200 here *is* the test.
        $response = Http::timeout(self::TIMEOUT)
            ->post('https://api4.thetvdb.com/v4/login', ['apikey' => $key]);

        return filled($response->json('data.token'))
            ? $this->pass('Working.')
            : $this->fail($this->reason($response->json('message'), $response->status()));
    }

    private function lastfm(string $key): array
    {
        $response = Http::timeout(self::TIMEOUT)
            ->get('https://ws.audioscrobbler.com/2.0/', [
                'method' => 'artist.getinfo',
                'artist' => 'Radiohead',
                'api_key' => $key,
                'format' => 'json',
            ]);

        // Last.fm returns HTTP 403 with an `error` code for a bad key, but has
        // also been known to answer 200 with the same body -- so the body is
        // what decides, not the status.
        if (filled($response->json('error'))) {
            return $this->fail($this->reason($response->json('message'), $response->status()));
        }

        return filled($response->json('artist.name'))
            ? $this->pass('Working.')
            : $this->fail('The service answered, but not with anything recognisable.');
    }

    private function acoustid(string $key): array
    {
        // No fingerprint to send, so this request is deliberately incomplete:
        // AcoustID validates the client key regardless, and answers 400 either
        // way. What distinguishes the two cases is its error *code*, not the
        // status -- code 4 is an invalid key, and anything else means the key
        // was accepted and only the missing fingerprint was objected to.
        //
        // Matched on the code rather than the message. A substring check for
        // "client" passed a key AcoustID had plainly rejected, because the
        // message reads "invalid API key" and never mentions the client.
        $response = Http::timeout(self::TIMEOUT)
            ->get('https://api.acoustid.org/v2/lookup', [
                'client' => $key,
                'meta' => 'recordings',
                'duration' => 100,
                'fingerprint' => '',
            ]);

        $code = $response->json('error.code');

        // 4 = invalid API key, 6 = invalid client (both are "your key is bad").
        if (in_array($code, [4, 6], true)) {
            return $this->fail($this->reason($response->json('error.message'), $response->status()));
        }

        return $this->pass('Working.');
    }

    private function openSubtitles(string $key): array
    {
        $response = Http::timeout(self::TIMEOUT)
            ->withHeaders([
                'Api-Key' => $key,
                'User-Agent' => (string) config('subtitles.user_agent', 'SoundChex v1.0'),
                'Accept' => 'application/json',
            ])
            ->get('https://api.opensubtitles.com/api/v1/infos/user');

        if ($response->successful()) {
            $remaining = $response->json('data.allowed_downloads');

            return $this->pass(
                $remaining === null
                    ? 'Working.'
                    : 'Working — '.$remaining.' downloads a day on this account.',
            );
        }

        return $this->fail($this->reason($response->json('message'), $response->status()));
    }

    private function spotify(string $id, string $secret): array
    {
        if ($secret === '') {
            return $this->fail('Both the client id and the secret are needed to test.');
        }

        $response = Http::timeout(self::TIMEOUT)
            ->asForm()
            ->post('https://accounts.spotify.com/api/token', [
                'grant_type' => 'client_credentials',
                'client_id' => $id,
                'client_secret' => $secret,
            ]);

        return filled($response->json('access_token'))
            ? $this->pass('Working.')
            : $this->fail($this->reason($response->json('error_description'), $response->status()));
    }

    /**
     * OMDb, by a well-known id rather than a search.
     *
     * The trap this has to get right: OMDb answers **200 with
     * `{"Response":"False","Error":"Invalid API key!"}`** for a bad key, so the
     * status says nothing and the body decides. A tester that trusted the
     * status would call every wrong key working -- which is worse than having
     * no test, because it would actively mislead.
     *
     * `tt0111161` is The Shawshank Redemption: a title that has been in the
     * database for decades and will not quietly disappear and make a good key
     * look broken.
     */
    private function omdb(string $key): array
    {
        $response = Http::timeout(self::TIMEOUT)
            ->get('https://www.omdbapi.com/', [
                'i' => 'tt0111161',
                'apikey' => $key,
                // Nothing of the body is used beyond the flag, so ask for the
                // smaller one.
                'plot' => 'short',
            ]);

        // The body is read first, whatever the status. A bad key gets **401
        // with the explanation in the body** -- `{"Response":"False","Error":
        // "Invalid API key!"}` -- so branching on the status would discard the
        // one sentence worth showing and print "The service rejected that key"
        // instead. Verified against the live API, which is how this was
        // caught: the first version did exactly that.
        $body = (array) $response->json();

        if (($body['Response'] ?? 'False') !== 'True') {
            return $this->fail($this->reason($body['Error'] ?? null, $response->status()));
        }

        if (! $response->successful()) {
            return $this->fail($this->reason(null, $response->status()));
        }

        return $this->pass('Working.');
    }

    /**
     * The service's own explanation where it gave one, ours where it did not.
     *
     * Preferring theirs on purpose: "Invalid API key: You must be granted a
     * valid key" tells somebody what to do, and "Request failed (401)" does
     * not.
     */
    private function reason(mixed $message, int $status): string
    {
        $message = is_string($message) ? trim($message) : '';

        if ($message !== '') {
            return $message;
        }

        return $status === 401 || $status === 403
            ? 'The service rejected that key.'
            : 'The service answered with an error ('.$status.').';
    }

    /** @return array{ok: bool, message: string} */
    private function pass(string $message): array
    {
        return ['ok' => true, 'message' => $message];
    }

    /** @return array{ok: bool, message: string} */
    private function fail(string $message): array
    {
        return ['ok' => false, 'message' => $message];
    }
}
