<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Policies;

use Filament\Facades\Filament;
use Illuminate\Foundation\Auth\User as AuthUser;
use Spatie\Permission\Models\Role;
use Illuminate\Auth\Access\HandlesAuthorization;

class RolePolicy
{
    use HandlesAuthorization;

    public function before(AuthUser $authUser, string $ability): bool | null
    {
        if (method_exists($authUser, 'hasRole') && $authUser->hasRole('super_admin')) {
            return true;
        }

        return null;
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