<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Policies;

use Filament\Facades\Filament;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;
use Spatie\Permission\Models\Role;

class RolePolicy
{
    use HandlesAuthorization;

    /**
     * A super admin bypasses every check below. Returning null rather than false
     * for everyone else is what lets the individual methods decide — false here
     * would deny the ability outright.
     *
     * The `method_exists` guard is because this is typed against the framework's
     * base user, which has no `hasRole()` until Spatie's trait is on the model.
     */
    public function before(AuthUser $authUser, string $ability): bool | null
    {
        return method_exists($authUser, 'hasRole') && $authUser->hasRole('super_admin')
            ? true
            : null;
    }

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:Role');
    }

    public function view(AuthUser $authUser, Role $role): bool
    {
        return $authUser->can('View:Role') && $this->canAccessRoleInCurrentTenant($role);
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:Role');
    }

    public function update(AuthUser $authUser, Role $role): bool
    {
        return $authUser->can('Update:Role') && $this->canAccessRoleInCurrentTenant($role, allowGlobal: false);
    }

    public function delete(AuthUser $authUser, Role $role): bool
    {
        return $authUser->can('Delete:Role') && $this->canAccessRoleInCurrentTenant($role, allowGlobal: false);
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:Role');
    }

    public function restore(AuthUser $authUser, Role $role): bool
    {
        return $authUser->can('Restore:Role');
    }

    public function forceDelete(AuthUser $authUser, Role $role): bool
    {
        return $authUser->can('ForceDelete:Role');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:Role');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:Role');
    }

    public function replicate(AuthUser $authUser, Role $role): bool
    {
        return $authUser->can('Replicate:Role');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:Role');
    }

    protected function canAccessRoleInCurrentTenant(Role $role, bool $allowGlobal = true): bool
    {
        if (! Filament::hasTenancy()) {
            return true;
        }

        $tenantId = Filament::getTenant()?->getKey();
        $teamForeignKey = config('permission.column_names.team_foreign_key', 'team_id');
        $roleTeamId = $role->getAttribute($teamForeignKey);

        if ($allowGlobal && ($roleTeamId === null)) {
            return true;
        }

        return (string) $roleTeamId === (string) $tenantId;
    }
}
