<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Refuses anything that did not come from this machine.
 *
 * For routes the host application uses to inspect its own server. They carry
 * no library data, but they describe the machine — what is running, what has
 * failed, where it can be reached — and that is nobody else's business.
 *
 * Checked against the connection's own address rather than a header, since a
 * header is set by whoever is asking.
 */
class LoopbackOnly
{
    public function handle(Request $request, Closure $next): Response
    {
        $ip = $request->ip();

        if (! in_array($ip, ['127.0.0.1', '::1', 'localhost'], true)) {
            abort(404);
        }

        return $next($request);
    }
}
