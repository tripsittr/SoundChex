---
name: soundchex-pr-flow
description: Everything reaches main through a pull request, and every PR gets a changelog file in changelog/NNN-name.md
metadata:
  type: feedback
---

**Every change reaches `main` through a pull request.** No direct pushes, no
local merges — 100% of the time.

**Every PR gets a changelog**: `changelog/NNN-short-name.md`, numbered for the
PR, written when the PR is opened rather than after it merges. One file per PR
rather than a single CHANGELOG.md, which conflicts on every branch.

Written for whoever reads it in six months: what changed from the outside, why
where the reason is not obvious, what needs an action on another machine, and
**what is still wrong**. A changelog that lists only wins is one nobody
believes twice.

**Why:** 100 commits became one merge with no readable account of what it
contained; the PR body was the only summary and it lives on GitHub rather than
in the repository.

**How to apply:** run all three suites before opening, write the changelog and
the PR body to say the same thing, and state failures and unverified claims
plainly. See rule 6 in AGENTS.md. Related: [[soundchex-issue-tracking]].
