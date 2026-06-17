<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class DefaultMusicOrgRolesSeeder extends Seeder
{
    /**
     * Seed starter role templates and baseline permissions.
     */
    public function run(): void
    {
        // Null team id creates global role templates that can be copied per organization.
        setPermissionsTeamId(null);

        $permissionsForOrganizationAdmin = [
            'Create:User',
            'Update:User',
            'Delete:User',
            'DeleteAny:User',
            'ViewAny:Role',
            'View:Role',
            'Create:Role',
            'Update:Role',
        ];

        foreach ($permissionsForOrganizationAdmin as $permissionName) {
            Permission::findOrCreate($permissionName, 'web');
        }

        $roleTemplates = [
            'organization_admin',
            'artist',
            'label_rep',
            'manager',
            'social_media_manager',
            'booking_agent',
            'tour_manager',
            'publicist',
            'producer',
            'sound_engineer',
            'videographer',
            'merch_manager',
            'a_and_r',
        ];

        foreach ($roleTemplates as $roleName) {
            Role::findOrCreate($roleName, 'web');
        }

        Role::findByName('organization_admin', 'web')
            ->syncPermissions($permissionsForOrganizationAdmin);
    }
}
