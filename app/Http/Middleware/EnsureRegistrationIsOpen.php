<?php

namespace App\Http\Middleware;

use App\Filament\Pages\Settings;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Blocks public sign-up when the household has closed it.
 *
 * The setting existed on the settings page but nothing read it, so
 * registration was always open — including to anyone who found the URL once
 * the server was made publicly reachable. Household members are added from the
 * admin panel instead.
 *
 * A 404 rather than a 403: a closed door should not advertise that there is a
 * door.
 */
class EnsureRegistrationIsOpen
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(static::isOpen(), 404);

        return $next($request);
    }

    /**
     * Whether anyone may create their own account.
     *
     * Defaults to closed when the setting has never been saved: a server that
     * might be public should not accept sign-ups because nobody has visited
     * the settings page yet.
     */
    public static function isOpen(): bool
    {
        return (bool) (Settings::getStoredSettings()['allow_registration'] ?? false);
    }
}
