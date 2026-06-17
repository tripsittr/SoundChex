<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class DefaultMusicOrgRolesSeeder extends Seeder
{
    /**
     * User-type groups that describe an entity (team/org) rather than a
     * person's role, and therefore should not become assignable roles.
     */
    protected array $excludedGroups = [
        'Team Types',
        'Agencies & Businesses (Org Type)',
    ];

    /**
     * Seed role templates that mirror the user-type taxonomy.
     */
    public function run(): void
    {
        // Null team id creates global role templates that can be copied per organization.
        setPermissionsTeamId(null);

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

        // Administrative roles get the elevated user/role management permissions.
        // (super_admin is handled by Shield's gate interception and needs no explicit grant.)
        Role::findByName('admin', 'web')->syncPermissions($adminPermissions);
    }

    /**
     * Build the list of role names from the user-type config, excluding
     * entity-type groups so only person roles (plus admin/super_admin) remain.
     *
     * @return array<int, string>
     */
    protected function roleNames(): array
    {
        $names = [];

        foreach (config('user_types.options', []) as $group => $types) {
            if (in_array($group, $this->excludedGroups, true)) {
                continue;
            }

            foreach (array_keys($types) as $key) {
                $names[$key] = $key;
            }
        }

        return array_values($names);
    }
}
