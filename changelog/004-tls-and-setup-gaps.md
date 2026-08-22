# 004 — What a real transfer attempt found

**Merged** pending · **Issues** S-74, S-75, GitHub #4

The first transfer between two machines failed before it started, and the
reason was a setup step nobody had written down.

## What changed

### A TLS failure says what to do about it

`cURL error 60: unable to get local issuer certificate` — accurate, and useless.

The source's certificate was a valid Let's Encrypt one with a complete chain
verifying cleanly. The receiving machine simply had nothing to verify against:
**Windows PHP ships with no CA bundle**, and leaves `curl.cainfo` and
`openssl.cafile` unset. macOS and Linux never hit it because the system bundle
is already there.

A failed connection now names which of the four common causes it was — no
certificate bundle, a name that will not resolve, a refused connection, a
timeout — and what to do. Anything unrecognised is passed through unchanged,
because a wrong guess is worse than a raw message.

### The setup instructions cover the two silent failures

Both fail without saying why, which is what earns them the space in the README:

- **`storage:link`** was missing from the README entirely. Skip it and every
  avatar and artist image 404s, with nothing explaining it.
- **The certificate bundle**, which affects far more than transfers — TMDB,
  MusicBrainz, artwork and subtitles all fail the same way, and all of them
  read as a network problem rather than a missing file.

### Building the Mac app, when the DMG step fails

`npm run build:server` can fail at `bundle_dmg.sh` while the `.app` itself
builds fine — and each failure leaves a mounted disk image behind that breaks
the next attempt, so retrying makes it worse. Recorded with the recovery steps
(S-74), because working around it silently means rediscovering it.

## Worth knowing

- **No migrations.**
- **Tauri exits `0` when the DMG bundling fails**, printing "failed to bundle
  project" and then reporting success. That is the third time in a day an exit
  code has said the opposite of the output — worth reading output rather than
  status.

## Still wrong

- **The transfer has still not completed between two machines.** This fixes
  what stopped the first attempt; whether the rest works end to end is unknown.
- The Windows CA bundle has to be installed by hand on `a5` before it will.

## Tests

**312 PHP · 87 Vitest.** Five new tests cover the connection failure messages,
including that an unrecognised error is passed through rather than guessed at.

Playwright was not run, deliberately. This changes documentation, one PHP
service and one PHP test — no Blade, no CSS, no JavaScript — so 13 minutes of
browser tests could not have caught anything the PHP suite did not. That
judgement is now a rule rather than a case-by-case decision (AGENTS.md 6).
