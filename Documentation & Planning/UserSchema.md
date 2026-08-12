# Users & Organizations

## Model

An **Organization** is the tenant — a household, family, or friend group sharing
a library. Users belong to many organizations via `organization_user`.

## Roles

Roles are tenant-scoped via Spatie teams. The full taxonomy is three roles:

| Role     | Scope        | Can do                                                        |
| -------- | ------------ | ------------------------------------------------------------- |
| `owner`  | Organization | Everything in the org, including managing members and billing |
| `admin`  | Organization | Manage members, roles, and the full catalog                   |
| `member` | Organization | Browse the catalog, add and rate items                        |

`super_admin` is **platform-level only**. It is never assignable from inside a
tenant — see `protected` in `config/organization_roles.php`. Only a super admin
on the `/admin` panel may grant it.

## Source of Truth

- `config/user_types.php` — the taxonomy itself
- `config/organization_roles.php` — derives the assignable list from the above,
  minus protected platform roles
- `database/seeders/DefaultOrgRolesSeeder.php` — seeds the global role templates

These three must stay in sync; the latter two read from the first, so edit
`user_types.php` only.

## Notes

- New self-signup organizations assign `admin` to the creator
  (`default_self_signup_role`).
- Role checks depend on team context being set — the
  `SyncPermissionTeamFromFilamentTenant` middleware handles this on the
  customer panel.
- The pre-pivot music-industry taxonomy (label, studio, venue, and agency
  roles) was removed in the catalog pivot. See
  `Documentation & Planning/AITaggerPivot.md`.
