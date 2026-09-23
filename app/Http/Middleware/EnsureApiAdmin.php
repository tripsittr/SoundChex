<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Http\Middleware;

use App\Services\CurrentProfile;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guards the app's admin API to a profile that may administer.
 *
 * The same test the web panel's door uses (owner or library-admin), read from
 * the token's current profile — never from anything the client sends. A profile
 * without it gets a 403, so the app can hide the admin surface but the server
 * does not depend on it having.
 */
class EnsureApiAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $profile = app(CurrentProfile::class)->get();

        // canAdministerLibrary() alone, matching the web panel's door
        // (Filament\Concerns\RestrictsToAdmins): it resolves through can(),
        // which short-circuits true for the owner, so an explicit isOwner()
        // here would only restate that — and a second copy of the rule is a
        // second place for it to drift.
        abort_if(
            $profile === null || ! $profile->canAdministerLibrary(),
            403,
            'This profile cannot administer the library.',
        );

        return $next($request);
    }
}
