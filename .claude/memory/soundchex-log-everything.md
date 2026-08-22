---
name: soundchex-log-everything
description: Log every operation that can fail — network calls, batch jobs, storage writes, background work — with enough detail to diagnose it from the device report alone
metadata:
  type: feedback
---

Add logging to anything that can fail, as a matter of course rather than after
it fails. Network requests, batch operations, storage writes, background jobs,
service worker decisions, anything with a `catch`.

A `catch` that only shows a toast is not enough: it tells the user something
went wrong and tells us nothing about what. Every failure path should record
what was attempted, what came back, and the identifier of the thing it was
working on.

**Why:** the app runs on a phone that is not in the room, so a bug that is not
logged is a bug reported as "it didn't work" with no way to reach the cause.
The IndexedDB connection failure was only ever diagnosed because the device
report happened to capture the unhandled rejection — 27 of them behind one
`UnknownError`. Downloads themselves had no logging at all until it was added
deliberately, so a file that never arrived left nothing behind.

**How to apply:** client-side use `window.soundchexDiagnostics?.record?.(kind,
detail)` so it reaches the device report; server-side use `Log::warning` or
`Log::error` with context. Wrap the reporting call so a missing reporter cannot
itself throw. Prefer a specific `kind` (`download:failed`, not `error`) and
always include the id of whatever was being acted on.
