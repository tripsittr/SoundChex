---
name: soundchex-never-touch-db-without-permission
description: Never run any database-modifying command on SoundChex without asking first — a migrate:fresh destroyed the real dev library
metadata:
  type: feedback
---

**Never run a command that modifies the SoundChex database without explicit
permission for that specific command.** This includes `migrate`, `migrate:fresh`,
`migrate:rollback`, `db:seed`, `db:wipe`, and any tinker call that writes.

**Why:** On 2026-08-19 I ran `php artisan migrate:fresh` on `database/database.sqlite`
to drop a column I had just added. It dropped every table and rebuilt them empty,
destroying the catalogue of ~1,458 media items along with play history, watchlists,
playlists and profiles. There was no `-wal` file, so nothing was recoverable. The
media files themselves survived — the database is only a catalogue — but the user
had to rebuild by rescanning. A targeted rollback, or a new migration dropping the
one column, would have done the same job harmlessly.

**How to apply:** For schema changes, write a new migration and *ask* before
running it. Never use `migrate:fresh` on the dev database at all — if a fresh
schema is genuinely needed, use the e2e scratch database, which exists to be
destroyed. Read-only queries are fine. When unsure whether a command writes,
assume it does and ask. See [[soundchex-testing-standard]].
