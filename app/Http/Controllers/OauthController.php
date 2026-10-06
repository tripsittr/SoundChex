<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Http\Controllers;

use App\Models\Profile;
use App\Services\CurrentProfile;
use App\Services\Oauth\OauthFlow;
use App\Services\Oauth\OauthProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;

/**
 * Sends an admin out to a service and receives them back with a token (#490).
 *
 * Two routes rather than one, because the outbound leg must generate and
 * remember a `state` that the inbound leg checks. Doing both in one action
 * would mean either trusting the state the service echoed back — which is the
 * attack — or not using one.
 */
class OauthController extends Controller
{
    /**
     * Starts a sign-in.
     *
     * Behind the server-administration permission, not merely `auth`. The
     * result is a credential stored server-wide, shared by every profile on the
     * install, so this is a machine decision rather than a library one — the
     * same reasoning as the Integrations page it is reached from.
     */
    public function redirect(Request $request, string $provider)
    {
        $this->authorizeAdmin();

        $service = OauthProvider::tryFromSlug($provider);

        if ($service === null) {
            abort(404);
        }

        $authorize = app(OauthFlow::class)->authorizeUrl($service);

        if ($authorize === null) {
            return redirect()
                ->to($this->integrationsUrl())
                ->with('oauth_error', 'Enter the '.$service->label().' client id and secret before signing in.');
        }

        // Kept in the session, and scoped per provider so starting a Spotify
        // sign-in cannot satisfy a Trakt callback.
        $request->session()->put($this->stateKey($service), $authorize['state']);

        return redirect()->away($authorize['url']);
    }

    /**
     * Receives the service's answer.
     *
     * Every failure path lands back on the integrations page with a sentence,
     * because this is a browser redirect and an exception here shows a stack
     * trace to somebody who was in the middle of a setup.
     */
    public function callback(Request $request, string $provider)
    {
        $this->authorizeAdmin();

        $service = OauthProvider::tryFromSlug($provider);

        if ($service === null) {
            abort(404);
        }

        $expected = $request->session()->pull($this->stateKey($service));

        // The user declining is a normal outcome and says so plainly, rather
        // than being reported as an error.
        if (filled($request->query('error'))) {
            return redirect()->to($this->integrationsUrl())->with(
                'oauth_error',
                $request->query('error') === 'access_denied'
                    ? 'Sign-in cancelled — nothing was connected.'
                    : $service->label().' refused the sign-in.',
            );
        }

        $state = (string) $request->query('state', '');

        // Compared in constant time, and only after confirming we issued one at
        // all. Accepting a callback with no stored state would accept a
        // callback nobody here started, which is exactly the cross-site request
        // the state exists to stop.
        if (blank($expected) || ! hash_equals((string) $expected, $state)) {
            Log::warning('An OAuth callback arrived with a state we did not issue', [
                'provider' => $service->value,
                'had_stored_state' => filled($expected),
            ]);

            return redirect()->to($this->integrationsUrl())->with(
                'oauth_error',
                'That sign-in could not be verified. Start it again from this page.',
            );
        }

        $code = (string) $request->query('code', '');

        if ($code === '') {
            return redirect()->to($this->integrationsUrl())
                ->with('oauth_error', $service->label().' sent no authorisation code.');
        }

        $result = app(OauthFlow::class)->exchange($service, $code);

        return redirect()->to($this->integrationsUrl())->with(
            $result['ok'] ? 'oauth_success' : 'oauth_error',
            $result['message'],
        );
    }

    /**
     * Refuses anybody who may not administer the server.
     *
     * The same permission the Integrations page is gated on. A household member
     * who can manage the library has no reason to bind the install to their own
     * Spotify account.
     */
    private function authorizeAdmin(): void
    {
        $profile = app(CurrentProfile::class)->get();

        abort_unless(
            $profile !== null && $profile->can(Profile::SERVER_ADMINISTRATION),
            403,
        );
    }

    private function stateKey(OauthProvider $provider): string
    {
        return 'oauth_state.'.$provider->value;
    }

    /**
     * Where to land afterwards.
     *
     * By route name, so a panel moved or renamed cannot leave these redirects
     * pointing at a 404. Falls back to the current path if the panel is not
     * registered, which only happens in isolation in a test.
     */
    private function integrationsUrl(): string
    {
        return Route::has('filament.admin.pages.integrations')
            ? route('filament.admin.pages.integrations')
            : '/admin/integrations';
    }
}
