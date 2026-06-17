<?php

namespace App\Policies;

use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class UserPolicy
{
    use HandlesAuthorization;

    public function before(User $user, string $ability): bool | null
    {
        if ($user->hasRole('super_admin')) {
            return true;
        }

        return null;
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
        return $user->can('Update:User') && $this->sharesOrganization($user, $record);
    }

    public function delete(User $user, User $record): bool
    {
        return $user->can('Delete:User') && $this->sharesOrganization($user, $record);
    }

    public function deleteAny(User $user): bool
    {
        return $user->can('DeleteAny:User');
    }

    public function restore(User $user, User $record): bool
    {
        return $user->can('Restore:User') && $this->sharesOrganization($user, $record);
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

    protected function sharesOrganization(User $user, User $record): bool
    {
        if ($user->is($record)) {
            return true;
        }

        $organizationIds = $user->organizations()->pluck('organizations.id');

        if ($organizationIds->isEmpty()) {
            return false;
        }

        return $record->organizations()
            ->whereIn('organizations.id', $organizationIds)
            ->exists();
    }
}
