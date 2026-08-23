---
name: soundchex-break-the-test-to-trust-it
description: "A test written against a fix tends to inherit the fix's assumptions and pass without it; delete the code and watch before believing any new test"
metadata: 
  node_type: memory
  type: feedback
  originSessionId: ad369037-1b3f-4737-96d3-96dfa841d301
  modified: 2026-08-23T08:41:51.670Z
---

**Delete the code under a new test and run it. If it stays green, the test is
decoration.** Do this for every test written against a fix, without exception.

On 23 August 2026 **four** tests written in one session could not have failed:

- A catalogue-compression test re-implemented the compression inline instead of
  calling it, so restoring `gzopen('php://output')` — the exact bug — left it
  green.
- A "transfer survives its own catalogue import" test asserted a row still
  existed, but under `:memory:` the row was never at risk; removing the
  carry-across changed nothing.
- A "film left alone when ffprobe cannot answer" test exited on an earlier
  branch (file not present) and never reached the guard it named.
- A duplicate-keeper test set up **one** candidate, and with one candidate
  `first()` returns it whatever the ordering rule is, so deleting the
  preference entirely left all sixteen green.

**Why:** a test written *after* a fix is written by someone holding the fix's
model of the problem, so it tends to assert the thing the fix already
guarantees rather than the thing that was broken. All four looked reasonable
and three read as thorough.

**How to apply:** break it at the guard, not near it. Ask what the test would
do if the code were absent, and if the answer is "take a different branch and
still pass", it is testing the branch and not the guard. Watch particularly for
a test whose setup has only one of the thing being chosen between — a
preference cannot be got wrong when there is nothing to prefer.

The same failure appears in monitoring: a stall detector that watched only
copied files reported a stall while a manifest was building perfectly well, and
a dead queue worker looked identical to a hung transfer because nothing checked
the worker. Silence is not success, and one symptom is not a diagnosis. See
[[soundchex-audit-tests-before-done]] and [[soundchex-testing-standard]].
