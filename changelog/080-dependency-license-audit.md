# 080 — Dependency license audit

**Merged** 2026-09-17 · **Issues** S-155

Audited every third-party dependency's license against our AGPL-3.0-or-later
license. **Result: clean** — nothing needs removing or replacing.

## What was checked

- **PHP / Composer — 147 packages.** 112 MIT, 33 BSD-3-Clause, plus Apache-2.0,
  LGPL-3.0, MPL-2.0. Four are multi-licensed with a GPL option but each also
  offers a permissive one (getid3 → LGPL/MPL; the three nette packages →
  BSD-3-Clause). No package is GPL-2.0-**only** without a permissive alternative
  — verified programmatically.
- **npm — 158 packages.** All permissive (MIT/ISC/Apache/BSD/MPL/0BSD/…). The
  one `MIT OR GPL-3.0-or-later` is taken as MIT.
- **Rust / Cargo — 524 crates.** Overwhelmingly `MIT OR Apache-2.0`; all
  permissive. The only AGPL entry is our own `soundchex` crate.

Every dependency offers at least one AGPLv3-compatible license.

## Recorded

- `Documentation & Planning/LicenseAudit.md` — the full report, the re-run
  commands, and the distinction between this (a mechanical compliance audit) and
  legal review of the policy documents (a human-attorney task, website W-14).
- **S-155** tracks the follow-ups: a CI/pre-release license **gate**, and
  generating `THIRD-PARTY-LICENSES.txt` for the bundled-server release (S-151).

## Note on ffmpeg

Not a linked dependency — the app shells out to a user-installed binary. Its
*bundling* license is handled in S-151 (full GPL ffmpeg, fine because we are
AGPLv3). Not part of this dependency audit.
