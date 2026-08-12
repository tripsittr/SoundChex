<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Seeds the roles for a self-hosted install: owner and admin run the server,
 * members browse the library.
 */
class DefaultOrgRolesSeeder extends Seeder
{
    public function run(): void
    {
        $adminPermissions = [
            'Create:User',
            'Update:User',
            'Delete:User',
            'DeleteAny:User',
            'ViewAny:Role',
            'View:Role',
            'Create:Role',
            'Update:Role',
        ];

        foreach ($adminPermissions as $permissionName) {
            Permission::findOrCreate($permissionName, 'web');
        }

        foreach ($this->roleNames() as $roleName) {
            Role::findOrCreate($roleName, 'web');
        }

        // Owner and admin both manage the library.
        // (super_admin is handled by Shield's gate interception and needs no
        // explicit grant.)
        foreach (['owner', 'admin'] as $roleName) {
            Role::findByName($roleName, 'web')->syncPermissions($adminPermissions);
        }
    }

    /**
     * Role names come from the user-type config so the seeded roles and the
     * role picker never drift apart.
     *
     * @return array<int, string>
     */
    protected function roleNames(): array
    {
        $names = [];

        foreach (config('user_types.options', []) as $types) {
            foreach (array_keys($types) as $key) {
                $names[$key] = $key;
            }
        }

        return array_values($names);
    }
}
