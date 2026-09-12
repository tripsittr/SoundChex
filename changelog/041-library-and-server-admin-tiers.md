# 041 — Two admin tiers, and a floor on the panel

**Merged** 2026-09-12 · **Issues** S-140

The management panel now has two clear tiers of access, and a floor beneath
them. Before this, it had neither: everyone could reach the panel chrome, and
each screen refused one at a time — and most screens asked for permissions that
were never created, so they were owner-only by accident rather than by
decision.

## What changed

### A library-administration permission

`Access:LibraryAdministration` is the content tier: metadata, uploads, library
settings, statistics, and the media resources. It sits below
`Access:ServerAdministration` (the machine — services, transfers, network,
acquisition), which now implies it, and above members and uploaders.

Granted per profile, through the checklist on the profile screen. Not through
a role: this app's permission unit is the profile — `Profile::can()` reads the
`profile_permissions` relation directly and never consults roles — so a grant
to the `admin` role would admit nobody and only look like it did. The owner
short-circuits and is not granted it explicitly.

### The gate, and why it has two keys

`RestrictsToAdmins::canAccess()` admits a profile that holds **either**:

- **library administration** — the broad key, which opens every content screen
  at once; or
- **the screen's own narrow permission** — `ViewAny:Music` for MusicResource,
  `View:Dashboard` for the dashboard — for a member trusted with one thing and
  not the panel at large.

The narrow path is preserved deliberately. It was the existing model, and a
member granted `ViewAny:Music` still reaches `/admin/music` and nothing else.

### A floor on the panel itself

`canAccessPanel()` now blocks a profile holding **no** admin-side permission at
the door, rather than admitting it to the chrome to be bounced from each page.
A profile with any grant — broad or narrow — still gets in, and the per-screen
gate decides what it sees once inside.

### Metadata Sources is reachable by library admins

It was owner-only by accident: it asked for `Access:MetadataSettings`, which
never existed, so only the owner short-circuit let anyone in. With the floor in
place, any library admin reaches it. This was the question that started the
work — "the metadata sources page is obsolete, no?" — and the answer is no, it
just had the wrong gate.

### The permissions UI reads like a decision

The owner already had a permission checklist on each profile, but it listed raw
names like `Access:LibraryAdministration`. It now shows **Library
administration** and **Server administration** with descriptions, leading the
list, so granting a tier is a clear choice rather than a guess at a string.

## Worth knowing

- **The migration has run on this machine**, after a `.backup`. It only creates
  the permission — granting is per profile — and is idempotent.
- **A decision was put to the owner mid-change and honoured**: the old
  "narrow grant reaches one resource" path conflicted with "below library
  admin, blocked entirely." The owner chose to keep narrow grants working, so
  "blocked entirely" now means a profile with *zero* admin permissions.
- **Metadata Sources and Integrations were not merged.** They coexist, each
  reachable by the right tier. Whether to fold one into the other is left open.

## Still wrong

- No existing profile is granted the new permission automatically — it is
  assigned per profile through the UI. On this install there is only the owner,
  who reaches everything regardless, so nothing is stranded; a multi-profile
  household will need the owner to grant the tier to each admin profile once.
- Roles (`admin`, `member`, `uploader`) exist but are not wired to profile
  access at all. They are vestigial here; the profile permission checklist is
  the real control. Worth a later decision on whether to retire them or connect
  them.

## Tests

**PHP 568** (new: `LibraryAdministrationAccessTest`, 9). Two existing suites —
`AccessControlTest` and `ServerAdministrationAccessTest` — were reconciled with
the new floor rather than worked around, and the panel gate was verified to
block a permissionless member on its own.
