<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\Response;

/**
 * Makes generated URLs match the address the request actually came in on.
 *
 * This server is reachable several ways at once — a LAN address, a tailnet IP,
 * and a public Funnel hostname — and the single `APP_URL` in `.env` can only be
 * one of them. A page reached over the LAN that generated a Funnel link (or the
 * reverse) sent every asset and redirect the long way round, or to an address
 * the device could not use at all.
 *
 * So for each web request, `app.url` and the URL generator's root are set to the
 * scheme + host (+ port) the request arrived on. `trustProxies` is already on,
 * so behind the relay the forwarded scheme and host are the real ones.
 *
 * This runs only for HTTP requests. The `.env` `APP_URL` still matters for the
 * queue and Artisan — emails and jobs have no request to read — which is why
 * `server:detect-address` keeps that value pointed at a real address too. The
 * two are complementary: this fixes per-request web links, that fixes the CLI
 * fallback.
 */
class SetAppUrl
{
    /**
     * Config key holding the address this server was configured with, which
     * this middleware deliberately leaves alone.
     *
     * Anything needing the server's own stable address rather than the one the
     * current request came in on reads this. OAuth redirect URIs are the case
     * that forced it (S-322): a service matches the redirect_uri against the
     * single one registered for the app, so it cannot move with the request.
     * It is defined in `config/app.php` from the same `APP_URL`, so it is set
     * before any middleware runs and survives a cached config.
     */
    public const CONFIGURED_URL = 'app.configured_url';

    public function handle(Request $request, Closure $next): Response
    {
        $scheme = $request->getScheme();          // http / https, via TrustProxies
        $host = $request->getHost();              // the forwarded host behind a proxy
        $port = $request->getPort();

        // Include the port only when it is non-default, so a clean https:// host
        // does not become https://host:443.
        $isDefaultPort = ($scheme === 'http' && $port === 80)
            || ($scheme === 'https' && $port === 443);

        $root = $scheme . '://' . $host . ($isDefaultPort || $port === null ? '' : ':' . $port);

        config(['app.url' => $root]);

        // The URL generator caches its root from config at boot, so setting the
        // config alone does not change url()/route() output — force it here.
        URL::forceRootUrl($root);
        URL::forceScheme($scheme);

        return $next($request);
    }
}
