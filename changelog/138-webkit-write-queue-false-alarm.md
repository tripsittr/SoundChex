# 138 — The WebKit write-queue timeout was a false alarm (S-278)

S-278 reported all seven `write-queue.spec.js` tests timing out in their
`beforeEach` on the `mobile-offline` (WebKit) project — filed as a pre-existing
service-worker bug while closing S-28.

## What was actually wrong

Nothing in the write-queue specs. Run in isolation, all seven pass (38s); run as
part of the full `mobile-offline` project solo, the whole project is green
(39/39). The original failure was observed during a run where **several
Playwright projects were executing at once against the one shared dev server and
its single SQLite database** — the same mistake that produced the misleading
mass-failure in S-28. Concurrent projects mutate each other's state, and one
symptom is a `beforeEach` `goto('/app')` that hangs until it times out.

So S-278 is a diagnosis error, not a defect. It is closed as not-a-bug.

## The actual fix — stop it recurring

Rather than "fix" a healthy spec, the fix is the guard against the misdiagnosis:
the Playwright config now states plainly that **only one project may run at a
time**, because all projects share one server and one database, and that a
failing run should be re-run solo before the failure is believed. A whole class
of "flaky on WebKit" reports came down to this.

## Verification

- `write-queue.spec.js` on `mobile-offline`, in isolation: 7/7.
- Full `mobile-offline` project, run solo: 39 passed, 0 failed.
