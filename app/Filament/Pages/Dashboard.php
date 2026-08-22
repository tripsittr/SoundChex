<?php

namespace App\Filament\Pages;

use App\Filament\Concerns\RestrictsToAdmins;
use Filament\Pages\Dashboard as BaseDashboard;

/**
 * The admin dashboard, gated like every other screen in the panel.
 *
 * Filament's stock Dashboard has no access check, and panel entry is open by
 * design — the household shares one login, so the account cannot be the
 * boundary. That left the dashboard as the one admin surface a capped profile
 * could open: the widgets reported library counts, storage totals and a
 * Recently Added table listing titles above that profile's rating.
 *
 * Every resource behind it already refused. This closes the front door.
 */
class Dashboard extends BaseDashboard
{
    use RestrictsToAdmins;

    /**
     * Shield generates this one as `View:Dashboard` rather than the `Access:`
     * prefix the trait derives for pages. Naming it here keeps the check
     * pointed at a permission that exists — the alternative fails closed for
     * everyone but the owner, which looks like working access control right
     * up until a granted admin is locked out.
     */
    protected static function requiredPermission(): string
    {
        return 'View:Dashboard';
    }
}
