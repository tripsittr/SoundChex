---
name: soundchex-issue-tracking
description: Every feature, fix and bug goes in Documentation & Planning/Issues.md before work starts; section order is In progress, Open, Deferred, Done
metadata:
  type: feedback
---

Write **every** feature, fix and bug to `Documentation & Planning/Issues.md`,
not only problems. An "issue" here means any tracked piece of work — a new
feature, an improvement, a bug — so a feature request gets an entry exactly as
a defect does.

Section order in the file, top to bottom: **In progress**, **Open**,
**Deferred**, **Done**. Done sits at the bottom because it is the section least
often read; Deferred sits above it because a decision not to do something is
still live information.

IDs are `S-nn` and never reused. Entries move between sections rather than
being deleted.

**Why:** small things were reported in conversation, half-fixed, and then
rediscovered later with no record of what had been decided — and one entry was
written down as broken without being checked, which is the failure the file
exists to prevent.

**How to apply:** when the user reports anything, add the entry before starting
work. **Update the status before moving on to the next thing, every time** —
not at the end of a session, not when asked. Finishing a fix and starting the
next task without moving the entry is how the file goes stale and stops being
worth reading.

When something is finished, move it to Done with the commit hash and what
verified it. Verify statuses against the code rather than trusting what an
entry claims — see [[soundchex-audit-tests-before-done]].
