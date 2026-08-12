# CLAUDE.md

**Read [AGENTS.md](AGENTS.md) first.** It is the source of truth for how this
project is built — architecture, conventions, and the production workflow.

This file exists only to point there, so the two can't drift apart.

## The short version

- **Never commit media.** The library is the user's own films, music and books.
- **This code moves and deletes real files.** Verify before destroying.
- **Verify, don't assume.** Every claim in a summary must be backed by
  something that ran.
- **No AI artifacts** in commits, PRs, or anything published.

Everything else — the data model, the metadata pipeline, the frontend traps,
the commit workflow, and what is and isn't built — is in
[AGENTS.md](AGENTS.md).
