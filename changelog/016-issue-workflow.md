# 016 — How issues are worked, and what the watcher can miss

**Merged** pending · **Issues** S-96, S-97 · GitHub #17, #18

Bookkeeping, no code. Two GitHub issues asked for a way of working rather than
a fix, and both belong in `Issues.md` like anything else.

## What changed

### S-96 — the workflow, from GitHub #17

Issues are never deleted. Each GitHub issue gets an entry in `Issues.md` and a
plan where the work is more than a line. Progress goes back as a reply on the
issue, and **anything uncertain is asked there rather than guessed at** — which
has already changed what happened: the answers about pointing survivors at
playlists, and about what `Sunnify` is, came back before anything was deleted.

In effect from #16 onward.

### S-97 — the watcher already saw comments, from GitHub #18

It did, and that is how the reply on #16 arrived. Verified rather than assumed,
and three limits recorded rather than left to be discovered:

- It notices comments by **count**, not by id. An added and a deleted comment
  between two polls would cancel out and both would be missed.
- It cannot see an **edited** comment, only a new one.
- It watches issues, not pull request comments.

None of those is worth solving today; all three are worth someone knowing
before they rely on it.

## Worth knowing

The reply on #18 said this was "tracked as S-97" before the entry existed —
true a minute later, false when written. Corrected here rather than left,
because an issue file that is right most of the time is the thing rule 5 exists
to prevent.

## Tests

**433 PHP · 87 Vitest**, unchanged — documentation only, so the two suites that
could catch something are the two that ran. Playwright not run, per the table
in AGENTS.md rule 6.

**Still failing, and not from this change:** the same 6 failures and 1 error,
all S-86.
