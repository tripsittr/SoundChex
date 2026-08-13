<?php

namespace App\Filament\Concerns;

use Illuminate\Support\Facades\Auth;

/**
 * Refuses anyone who may only add files.
 *
 * **Hiding a resource from navigation is not access control.** Filament routes
 * are reachable by URL whether or not they appear in the sidebar, so a
 * resource that merely omits itself from the menu is still open to anyone who
 * types the path. `canAccess()` is what actually refuses the request.
 *
 * Applied to every resource and page except the upload screen, because an
 * uploader reaching the panel must not thereby reach user management, the
 * metadata API keys, or any delete action.
 */
trait RestrictsToAdmins
{
    public static function canAccess(): bool
    {
        return Auth::user()?->isLibraryAdmin() ?? false;
    }

    /**
     * Keeps the sidebar honest as well.
     *
     * Cosmetic on its own — canAccess() above is the actual gate — but a menu
     * entry that 403s when clicked is worse than no entry.
     */
    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }
}
