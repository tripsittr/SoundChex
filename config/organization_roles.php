<?php

/*
|--------------------------------------------------------------------------
| Organization Roles
|--------------------------------------------------------------------------
|
| Assignable roles within an organization are derived from the user-type
| taxonomy (config/user_types.php) so the two stay in sync. Entity-type
| groups (team/org types) and super_admin are excluded — those are not
| roles you assign to a member inside a tenant.
|
*/

$excludedGroups = [
    'Team Types',
    'Agencies & Businesses (Org Type)',
];

/*
 * Protected, platform-level roles that must never be assignable from within a
 * tenant/organization context (invites, member role pickers, etc.). Only
 * super admins on the admin panel may grant these, preventing privilege
 * escalation by organization members.
 *
 * Note: 'admin' is the *organization* admin (tenant-scoped) and is intentionally
 * assignable — org creators receive it and may invite others as admin or lower.
 */
$protectedRoles = [
    'super_admin',
];

$excludedRoles = $protectedRoles;

$userTypes = require __DIR__ . '/user_types.php';

$assignable = [];

foreach (($userTypes['options'] ?? []) as $group => $types) {
    if (in_array($group, $excludedGroups, true)) {
        continue;
    }

    foreach ($types as $key => $label) {
        if (in_array($key, $excludedRoles, true)) {
            continue;
        }

        $assignable[$key] = $label;
    }
}

return [
    'assignable' => $assignable,

    // Platform-level roles that organization members may never assign.
    'protected' => $protectedRoles,

    // New self-signup organizations get an admin role by default; the owner
    // can refine their role and invite members with specific titles after.
    'default_self_signup_role' => 'admin',
];
