<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Policies;

use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class UserPolicy
{
    use HandlesAuthorization;

    /**
     * A super admin bypasses every check below. Returning null rather than false
     * for everyone else is what lets the individual methods decide — false here
     * would deny the ability outright.
     */
    public function before(User $user, string $ability): bool | null
    {
        return $user->hasRole('super_admin') ? true : null;
    }

    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, User $record): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return $user->can('Create:User');
    }

    public function update(User $user, User $record): bool
    {
        return $user->can('Update:User');
    }

    public function delete(User $user, User $record): bool
    {
        return $user->can('Delete:User');
    }

    public function deleteAny(User $user): bool
    {
        return $user->can('DeleteAny:User');
    }

    public function restore(User $user, User $record): bool
    {
        return $user->can('Restore:User');
    }

    public function forceDelete(User $user, User $record): bool
    {
        return false;
    }

    public function forceDeleteAny(User $user): bool
    {
        return false;
    }

    public function restoreAny(User $user): bool
    {
        return $user->can('RestoreAny:User');
    }

    public function replicate(User $user, User $record): bool
    {
        return false;
    }

    public function reorder(User $user): bool
    {
        return false;
    }
}
