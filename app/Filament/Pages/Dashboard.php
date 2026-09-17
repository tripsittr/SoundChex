<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

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
    /**
     * The narrow key. Library admins reach the dashboard through the floor;
     * this is the grantable `View:Dashboard` for a member given the landing
     * page without the rest of the panel. The default derivation would ask
     * for `Access:Dashboard`, which is not a real permission.
     */
    protected static function requiredPermission(): ?string
    {
        return 'View:Dashboard';
    }

    /**
     * Through to the statistics page.
     *
     * The dashboard answers "is this server healthy right now" — counts,
     * storage, what needs review, what is reachable. Listening over time is a
     * different question with its own page, and the two were starting to
     * duplicate each other: "plays this week" lived here and said less than
     * the statistics page says in a glance.
     */
    protected function getHeaderActions(): array
    {
        return [
            \Filament\Actions\Action::make('statistics')
                ->label('More stats')
                ->icon(\Filament\Support\Icons\Heroicon::OutlinedChartBar)
                ->color('gray')
                ->url(MusicStatisticsPage::getUrl())
                ->visible(fn (): bool => MusicStatisticsPage::canAccess()),
        ];
    }
}
