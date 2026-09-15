# 048 — Service install wrote to the wrong LaunchAgents

**Merged** 2026-09-15 · **Issues** S-142

Installing a host service from the admin panel failed with
`Could not write /Library/LaunchAgents/com.soundchex.serve.plist` — a path the
user never chose, and one no non-root process may write.

## What was wrong

`HostServices::agentPath()` built the target as
`($_SERVER['HOME'] ?? getenv('HOME')) . '/Library/LaunchAgents/…'`. When the
server runs from a context that carries no `HOME` — launchd itself, the desktop
shell's service command, cron — that prefix is empty and the path collapses to
the **root** `/Library/LaunchAgents`, which fails to write. `??` did not help:
`$_SERVER['HOME']` can be the empty string rather than unset, which `??` passes
straight through.

The log reader (`log()`) had the identical bug, reading from `/Library/Logs`
and so always finding "nothing logged."

## The fix

A single `homeDir()` that resolves the home from every source it might live in —
`$_SERVER`, the environment, and finally the passwd entry (`posix_getpwuid`),
which is set even when the environment is not — and returns null when it
genuinely cannot. `agentPath()` returns null rather than a root path, and every
caller now refuses with a clear message ("could not find your home directory…")
instead of attempting the write. `log()` uses the same resolution.

## Worth knowing

- **Server-side** — live once deployed, no rebuild.
- The failure was environment-dependent: it worked when the server was started
  from a login shell (HOME set) and failed from a bare service context. That is
  why it looked intermittent.

## Tests

`HostServicesTest` gains two: an empty HOME never yields a `/Library/...` target
(verified to fail against the old code — it has teeth), and a normal HOME gives
a path under it. Neither loads launchctl. Full PHP suite 572.
