---
name: soundchex-plan-workflow
description: Plans live in Documentation & Planning and are deleted once done — Issues.md is the record, not a DONE_ prefix
metadata:
  type: project
---

One plan at a time in `Documentation & Planning/`, finished before the next.

**A finished plan is deleted, not renamed.** Its entry in `Issues.md` records
what was built and what verified it. The `DONE_` prefix convention was dropped
on 22 August 2026, when the folder had reached 28 files — most of them plans
for work finished weeks earlier, several still claiming "Not started" for
things that had shipped.

What stays in that folder: plans nobody has started, and reference documents
that describe how something works rather than proposing work.

**Why:** a plan kept after the work is done is a second, staler answer to a
question `Issues.md` already answers — and the stale one is what a new reader
finds first.

**How to apply:** before deleting, check the plan against the code rather than
against its own status line. One said "Not started" and had shipped that
morning. See [[soundchex-issue-tracking]].
