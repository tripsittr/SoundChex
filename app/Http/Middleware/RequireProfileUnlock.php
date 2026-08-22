<?php

namespace App\Http\Middleware;

use App\Services\CurrentProfile;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Asks for the PIN when the app is opened on a locked profile.
 *
 * Sign-in is now long-lived — a media app that asks for a password on the train
 * is one whose downloads may as well not exist — so the session survives the
 * app being closed and reopened. That makes the session, on its own, no
 * evidence about who is holding the phone. The PIN is what carries that, and it
 * is asked for again after the unlock has aged out.
 *
 * Only for profiles that have a PIN. One without is unlocked by definition, and
 * a prompt there would be a lock with no key.
 */
class RequireProfileUnlock
{
    public function handle(Request $request, Closure $next): Response
    {
        $profiles = app(CurrentProfile::class);

        if ($profiles->isUnlocked()) {
            return $next($request);
        }

        // The lock screen itself, and signing out, must stay reachable — a
        // redirect loop would leave the app unusable with no way out of it.
        if ($request->routeIs('profiles.*') || $request->routeIs('logout')) {
            return $next($request);
        }

        // An API or fetch request gets a status rather than a redirect: the
        // client can decide whether to show the lock screen, where an HTML
        // redirect would be parsed as data and fail confusingly.
        if ($request->expectsJson()) {
            return response()->json(['message' => 'This profile is locked.'], 423);
        }

        return redirect()
            ->route('profiles.index')
            ->with('pin_for', $profiles->get()?->id);
    }
}
