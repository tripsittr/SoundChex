<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

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
 * **The gate is library administration.** Every screen using this trait is
 * content administration, and the question each asks is the same — "may this
 * profile curate the library?" — so they share one answer. The older
 * per-screen names (`ViewAny:Music`, `Access:BulkUpload`) were never created
 * as permissions, which silently made these screens owner-only; a profile
 * holding library administration now reaches all of them, and a member or
 * uploader reaches none. A screen that genuinely needs a narrower grant can
 * still override `requiredPermission()`, which is then required *in addition*
 * to the library-admin floor.
 */
trait RestrictsToAdmins
{
    public static function canAccess(): bool
    {
        $profile = app(CurrentProfile::class)->get();

        if ($profile === null) {
            return false;
        }

        // Library administration is the broad key: it opens every content
        // screen at once, which is what an admin profile holds.
        if ($profile->canAdministerLibrary()) {
            return true;
        }

        // Otherwise a profile reaches a screen only by holding that screen's
        // own permission — the narrow grant, for a member trusted with one
        // thing and not the panel at large. A screen with no permission of its
        // own (the default) is library-admin-only, since there is no narrow
        // key to hold.
        $permission = static::requiredPermission();

        return $permission !== null && $profile->can($permission);
    }

    /**
     * The *narrow* permission that also opens this screen, or null.
     *
     * Library administration opens everything; this is the alternative key for
     * a profile trusted with one screen and not the panel at large. Derived
     * from the class name — `MusicResource` → `ViewAny:Music` — so a resource
     * need not declare one, matching the names Shield generates and the
     * `AccessControlTest` relies on.
     *
     * Null for a screen with no such permission: a page whose name yields
     * nothing grantable is library-admin-only, which is the safe default
     * rather than inventing a key nobody can hold.
     */
    protected static function requiredPermission(): ?string
    {
        $class = class_basename(static::class);

        if (str_ends_with($class, 'Resource')) {
            return 'ViewAny:' . str_replace('Resource', '', $class);
        }

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
