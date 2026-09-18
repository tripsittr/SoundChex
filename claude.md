# CLAUDE.md

**Read [AGENTS.md](AGENTS.md) first.** It is the source of truth for how this
project is built — architecture, conventions, and the production workflow.

This file exists only to point there, so the two can't drift apart.

Three more, all worth reading before changing anything:

- **The admin Tracker** — every feature, fix and bug is tracked in the landing
  site's admin panel (`SoundChexWebsite` repo → `/admin` → Tracker / Board), not
  in `Issues.md` (now a pointer). See §5 of [AGENTS.md](AGENTS.md).
- **[Documentation & Planning/Handoff.md](Documentation%20&%20Planning/Handoff.md)**
  — where things stand, what is known broken, what was tried and abandoned.
- **[docs/WorkingOnSoundChex.md](docs/WorkingOnSoundChex.md)** — what this
  project has taught, mostly the hard way.

## The short version

- **Start at `Documentation & Planning/Status.md`**, then the active plan. One
  plan at a time, finished before the next. A plan is renamed `DONE_` only when
  built, tested and verified.
- **Everything gets an issue.** Any feature, fix or bug is logged in the admin
  **Tracker** *before* the work starts — a feature request the same as a defect.
  Add it in the panel, or `php artisan track:issue` in the `SoundChexWebsite`
  repo. Advance its **status** as work moves (Planned → In progress →
  Shipped/Done, or Deferred; drag the card on the Board). Nothing is deleted — a
  decision not to do something (Deferred) is worth as much as a fix. See §5 of
  AGENTS.md.
- **Log anything that can fail.** Network calls, batch work, storage writes,
  background jobs. A `catch` that only shows a toast says something broke and
  not what — and the phone that hit it is not in the room.
- **Everything reaches `main` through a pull request**, and every PR gets a
  changelog in `changelog/NNN-name.md`, written when the PR is opened. Say what
  is still broken as well as what was fixed.
- **Never commit media.** The library is the user's own films, music and books.
- **This code moves and deletes real files.** Verify before destroying.
- **Verify, don't assume.** Every claim in a summary must be backed by
  something that ran.
- **No AI artifacts** in commits, PRs, or anything published.

Everything else — the data model, the metadata pipeline, the frontend traps,
the commit workflow, and what is and isn't built — is in
[AGENTS.md](AGENTS.md).
