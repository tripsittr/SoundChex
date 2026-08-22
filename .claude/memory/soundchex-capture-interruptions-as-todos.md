---
name: soundchex-capture-interruptions-as-todos
description: Capture mid-turn requests into the todo list immediately and return to them, rather than handling or dropping them ad hoc
metadata:
  type: feedback
---

When the user raises a new issue, request or idea while work is already in
flight, add it to the todo list straight away and carry on with the current
task, then come back to it. Do not silently absorb it into whatever is being
worked on, and do not lose it.

**Why:** The user works by reporting problems as they find them, often several
within one turn while testing on device. Without capture, later items get
dropped when the current thread is long, and they have to repeat themselves.

**How to apply:** On any mid-turn request, call TodoWrite before continuing.
Keep the list reflecting *current* work — a stale list is worse than none,
because it hides what is actually outstanding. Tell the user what was captured
so they can see it was not lost. See
[[soundchex-reinstall-on-risky-changes]].
