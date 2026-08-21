# CLAUDE.md

**Read [AGENTS.md](AGENTS.md) first.** It is the source of truth for how this
project is built — architecture, conventions, and the production workflow.

This file exists only to point there, so the two can't drift apart.

Two more, both worth reading before changing anything:

- **[Documentation & Planning/Handoff.md](Documentation%20&%20Planning/Handoff.md)**
  — where things stand, what is known broken, what was tried and abandoned.
- **[docs/WorkingOnSoundChex.md](docs/WorkingOnSoundChex.md)** — what this
  project has taught, mostly the hard way.

## The short version

- **Start at `Documentation & Planning/Status.md`**, then the active plan. One
  plan at a time, finished before the next. A plan is renamed `DONE_` only when
  built, tested and verified.
- **Never commit media.** The library is the user's own films, music and books.
- **This code moves and deletes real files.** Verify before destroying.
- **Verify, don't assume.** Every claim in a summary must be backed by
  something that ran.
- **No AI artifacts** in commits, PRs, or anything published.

Everything else — the data model, the metadata pipeline, the frontend traps,
the commit workflow, and what is and isn't built — is in
[AGENTS.md](AGENTS.md).
