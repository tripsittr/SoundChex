# 145 — Activity Log: a unified audit timeline, as a bundled plugin

Auditing was scattered — `MetadataVersion` for metadata edits, `Notification`
for scan/episode events, `DeviceReport` for device diagnostics, `MediaPlay` for
playback — with no single "what happened" stream. This adds one, and does it as a
first-party bundled plugin, which makes it the flagship demonstration of the
whole plugin platform: it subscribes to the **entire** event catalogue and
contributes a **whole admin page**.

Those scattered trails are untouched; this is a timeline on top of the event
surface, not a replacement for any of them.

## What shipped

- **A plugin → Filament admin-page seam** (`Registry::adminPage()` /
  `adminPageClasses()`). Filament only auto-discovers pages under `app/Filament`;
  this lets a plugin register a `Filament\Pages\Page` from its own namespace, and
  `AdminPanelProvider` folds those into the panel's page list. The loader is
  booted (idempotently) before the registry is read, and a misbehaving plugin
  cannot take the panel down. This is new platform capability, usable by any
  plugin — the audit log is its first user.
- **`audit_log_entries` table + `AuditLogEntry` model** — one row per event:
  event name, a human summary, the acting profile (id + denormalised name), the
  subject (id/type + denormalised title), and a scalar context bag. Names are
  denormalised so the timeline still reads after the profile or item is gone —
  which matters, because one of the things it records is a deletion.
- **The bundled `activity-log` plugin** (`plugins/bundled/activity-log`,
  always-on):
  - Subscribes to every event via `Registry::builtInEvents()` + `Registry::on()`
    — so a newly-added event is captured with no change here.
  - `AuditRecorder` reflects over each event's public readonly properties to pull
    out actor / subject / context generically, rather than hand-coding forty
    payload shapes. Recording is best-effort and swallowed: auditing never fails
    the action it records.
  - Adds a filterable **Audit Log** page (event type, acting profile, search),
    gated to server admins via the existing `RestrictsToServerAdmins` concern.

## Tests

`ActivityLogPluginTest` (11): the admin-page seam registers and de-duplicates;
events with and without an actor/subject record correctly; a subject title
survives its item's deletion; context keeps scalars and drops object graphs;
recording never throws into the event; the page is gated to server admins and
renders end-to-end in the panel. Full suite green (843).

## Notes

- The table lives in a **core migration**, not a plugin-shipped one — an
  always-on bundled plugin should not run migrations at an odd time, and the
  schema versions with the app.
- `upload.completed` and any other still-inert events are captured the moment
  they start firing; the plugin needs no update for them.
