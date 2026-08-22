<?php

namespace App\Filament\Concerns;

use App\Services\CurrentProfile;

/**
 * Gates a resource on the current profile's permissions.
 *
 * **Hiding a resource from navigation is not access control.** Filament routes
 * resolve by URL whether or not they appear in the sidebar, so a resource that
 * merely omits itself from the menu is still open to anyone who types the
 * path. `canAccess()` is what actually refuses the request.
 *
 * The household shares one login, so the account cannot be the boundary —
 * everyone signing in would hold identical rights. The profile decides, and
 * the owner profile short-circuits so a household can never be left with
 * nobody able to administer it.
 *
 * A resource states which permission it needs by overriding
 * `requiredPermission()`; the default covers pages, which have one gate each.
 */
trait RestrictsToAdmins
{
    public static function canAccess(): bool
    {
        $profile = app(CurrentProfile::class)->get();

        if ($profile === null) {
            return false;
        }

        return $profile->can(static::requiredPermission());
    }

    /**
     * The permission this screen needs.
     *
     * Named from the class so every resource does not have to declare one:
     * MusicResource asks for ViewAny:Music, BulkUpload for Access:BulkUpload.
     */
    protected static function requiredPermission(): string
    {
        $class = class_basename(static::class);

        if (str_ends_with($class, 'Resource')) {
            return 'ViewAny:' . str_replace('Resource', '', $class);
        }

        // "LibrarySettingsPage" would ask for a permission that does not
        // exist and fail closed, so the Page suffix is dropped to match the
        // generated names.
        return 'Access:' . preg_replace('/Page$/', '', $class);
    }

    /**
     * Keeps the sidebar honest as well.
     *
     * Cosmetic on its own — canAccess() is the actual gate — but a menu entry
     * that 403s when clicked is worse than no entry.
     */
    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }
}
