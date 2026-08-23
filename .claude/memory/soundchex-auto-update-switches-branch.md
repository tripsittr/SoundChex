---
name: soundchex-auto-update-switches-branch
description: scripts/auto-update-main.ps1 runs on a schedule on the Windows machine and switches the working tree back to main, which fights any branch work
metadata:
  type: project
---

`scripts/auto-update-main.ps1` runs on a schedule on the Windows machine (every
five minutes, logging to `storage/logs/auto-update-main.log`). Two behaviours
worth knowing before doing any work there:

- **It switches the working tree back to `main`.** If it finds the repo on any
  other branch it runs `git switch main`. On 22 August 2026 it moved work off
  its feature branch mid-session, silently.
- **It skips its pull whenever a tracked file is dirty.** One uncommitted edit
  to `Issues.md` left the machine two commits stale for an afternoon, while the
  log reported it was checking every five minutes.

**Why:** the project's whole workflow is branch-then-PR (rule 6 in AGENTS.md),
and a scheduled job that yanks the tree to `main` is directly at odds with it.
Uncommitted work travels with the checkout so nothing is lost, but the branch
you think you are on is not the branch you are on.

**How to apply:** commit early on a feature branch there rather than
accumulating uncommitted work, and check `git rev-parse --abbrev-ref HEAD`
before committing rather than assuming. If a session will be long, consider
disabling the scheduled task first — and ask before doing so, it is the owner's
machine configuration. Related: [[soundchex-pr-flow]].
