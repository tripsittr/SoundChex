<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace Tests\Feature;

use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * The admin panel must generate URLs on the scheme the request arrived on.
 *
 * `AppServiceProvider` forces https when `APP_ENV=production`, which is right
 * for a tunnelled server on the public internet. `SetAppUrl` is what keeps that
 * honest per request, but it was prepended to the `web` and `api` groups only,
 * and Filament's panel declares its own middleware stack — so `/admin` never
 * got it.
 *
 * On the bundled desktop server, which serves plain http on the LAN, that made
 * `/admin` redirect to `https://127.0.0.1:8000/admin/login` against a port with
 * no TLS on it. The browser called it an insecure connection; the panel was
 * unreachable.
 */
class AdminPanelSchemeTest extends TestCase
{
    public function test_admin_redirects_on_the_scheme_the_request_arrived_on(): void
    {
        // What production does. Set directly rather than by switching APP_ENV,
        // because the provider that would do it has already booted.
        URL::forceScheme('https');

        $response = $this->get('http://127.0.0.1:8000/admin');

        $location = $response->headers->get('Location');

        $this->assertNotNull($location, 'Expected /admin to redirect when signed out.');

        $this->assertStringStartsWith(
            'http://',
            $location,
            "An http request must not be redirected to https by a server that only speaks http. Got: {$location}"
        );
    }

    public function test_a_forwarded_https_request_still_generates_https(): void
    {
        // The tunnelled case the forced scheme exists for: the relay terminates
        // TLS and forwards the real scheme, and trustProxies is on.
        $response = $this->withServerVariables(['HTTPS' => 'on'])
            ->get('https://library.example.test/admin');

        $location = $response->headers->get('Location');

        $this->assertNotNull($location, 'Expected /admin to redirect when signed out.');

        $this->assertStringStartsWith(
            'https://',
            $location,
            "A request that arrived over https must keep it. Got: {$location}"
        );
    }
}
