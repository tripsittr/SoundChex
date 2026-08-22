# For agents working on SoundChex

What one agent learned working on this, written down so the next one does not
learn it the same way.

**Start with [AGENTS.md](../AGENTS.md).** It is the source of truth — the rules,
the architecture, the workflow. This folder is the residue: preferences, hard
lessons, and a script.

## memory/

One file per thing worth remembering. [`MEMORY.md`](memory/MEMORY.md) indexes
them.

They fall into two kinds. **How the owner wants this built** — every feature
and bug in `Issues.md` before work starts, everything to `main` through a pull
request with a changelog, tests at all three layers, logging on anything that
can fail. And **what went wrong once and must not again** — a `migrate:fresh`
that destroyed the real library, a hardcoded database path that destroyed it a
second time under test.

The ones to read before touching anything:

| | |
| --- | --- |
| [`never-touch-db-without-permission`](memory/soundchex-never-touch-db-without-permission.md) | This is the only copy of a real library |
| [`testing-standard`](memory/soundchex-testing-standard.md) | And what makes a test worthless |
| [`pr-flow`](memory/soundchex-pr-flow.md) | Nothing reaches `main` any other way |
| [`log-everything`](memory/soundchex-log-everything.md) | The phone that hit the bug is not in the room |

**These are a copy.** They live in a local directory outside the repository and
are copied here as part of whatever change made them true. If you learn
something worth the next person knowing, write it and copy it across in the
same PR — see rule 6 in [AGENTS.md](../AGENTS.md).

## scripts/

[`watch-tests.sh`](scripts/watch-tests.sh) — a live view of a Playwright run.

```bash
npx playwright test --reporter=line > /tmp/pw.txt 2>&1 &
.claude/scripts/watch-tests.sh /tmp/pw.txt
```

The browser suite takes 13 minutes on one worker. This shows progress and, more
usefully, failures as they happen rather than at the end.

## Three things that will otherwise cost you a day

**Exit codes lie here.** Tauri prints "failed to bundle project" and exits `0`.
Playwright's wrapper exits `0` with failures in the output. In one session that
happened three times. Read the output.

**A test passing is not a test working.** Two of this project's tests passed
with the fix deliberately removed — one checked a path that did not exist, one
asserted on a size difference and never reached the hash check it was written
for. Break your fix on purpose and watch the test fail before believing it.

**"Fixed" needs a scope.** An IndexedDB bug was fixed in two of three databases
and reported fixed. It kept happening for a day, from the third. The mechanism
was right; the claim about coverage was not.

## What is not here

Anything about the user's media. The library is their own films, music and
books, and none of it — nor its paths, nor its contents — belongs in a
repository.
