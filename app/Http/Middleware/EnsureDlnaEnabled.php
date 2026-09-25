<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Http\Middleware;

use App\Services\Dlna\DlnaSettings;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guards the DLNA endpoints, which have no authentication of their own (S-7).
 *
 * DLNA is unauthenticated by design — a television cannot log in — so these
 * routes are open to whoever can reach them. Two gates stand in for the login
 * that cannot exist:
 *
 * 1. **Switched on.** Off by default, and off means 404 rather than 403: a
 *    server that is not offering DLNA should look like it has no DLNA, not
 *    like it has one that refused.
 * 2. **From the local network.** SSDP discovery is multicast and never leaves
 *    the LAN, but the HTTP endpoints it points at are ordinary routes on the
 *    same server that answers the public Funnel. Without this check, anyone
 *    who reached the public address could browse the library unauthenticated
 *    — which is the opposite of what "LAN only" promised.
 */
class EnsureDlnaEnabled
{
    /** Private ranges, loopback, and link-local — the networks a TV lives on. */
    private const LOCAL = [
        '127.0.0.0/8',
        '10.0.0.0/8',
        '172.16.0.0/12',
        '192.168.0.0/16',
        '169.254.0.0/16',
        '::1/128',
        'fc00::/7',
        'fe80::/10',
    ];

    public function __construct(private DlnaSettings $settings) {}

    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($this->settings->shouldRun(), 404);
        abort_unless($this->isLocal($request->ip()), 404);

        return $next($request);
    }

    /**
     * Whether the caller is on the local network.
     *
     * Deliberately not `$request->ip()`'s proxy-aware form: a forwarded header
     * is set by whoever sent the request, so trusting it here would let a
     * remote caller claim to be on the LAN.
     */
    private function isLocal(?string $ip): bool
    {
        if ($ip === null) {
            return false;
        }

        return IpUtils::checkIp($ip, self::LOCAL);
    }
}
