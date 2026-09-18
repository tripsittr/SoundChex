# Dependency license audit

**Audited 17 Sep 2026.** SoundChex is **AGPL-3.0-or-later**
([[soundchex-license]]). This audit confirms every third-party dependency
offers a license compatible with distributing SoundChex under AGPLv3. It is the
basis for the eventual `THIRD-PARTY-LICENSES.txt` that ships with the bundled
server (S-151).

**Not a substitute for legal review.** This is a *compliance* audit of
dependency licenses — a mechanical check that nothing we bundle forbids AGPL
distribution. The "draft pending legal review" on the policy documents is a
separate, human-attorney task (see below).

## Result: clean

Every dependency in all three ecosystems offers at least one AGPLv3-compatible
license. Nothing needs removing or replacing.

### PHP / Composer — 147 packages

| License | Count | Compatible? |
|---|---|---|
| MIT | 112 | ✅ permissive |
| BSD-3-Clause | 33 | ✅ permissive |
| Apache-2.0 | 2 | ✅ permissive |
| LGPL-3.0-only | 1 | ✅ AGPL-compatible |
| MPL-2.0 | 1 | ✅ AGPL-compatible |
| GPL-2.0-only / GPL-3.0-only / GPL-1.0-or-later (multi-licensed) | 3 pkgs | ✅ each also offered under BSD-3-Clause or LGPL/MPL |

Multi-licensed packages worth naming (we take the permissive option):
- `james-heinrich/getid3` — GPL-1.0-or-later **OR** LGPL-3.0-only **OR** MPL-2.0
  → take **LGPL-3.0** or **MPL-2.0** (both AGPL-compatible).
- `nette/php-generator`, `nette/schema`, `nette/utils` — BSD-3-Clause **OR**
  GPL-2.0/3.0 → take **BSD-3-Clause**. (Dev/build tooling.)

No package is GPL-2.0-**only** without a permissive alternative (that would be
the one real AGPL-incompatibility to watch for). Verified programmatically.

### npm — 158 packages

All permissive: MIT (118), ISC (12), Apache-2.0 (8), BSD-2/3-Clause, MPL-2.0,
MIT-0, 0BSD, CC0-1.0, BlueOak-1.0.0, and a few dual `MIT OR Apache-2.0`. The one
package offering `MIT OR GPL-3.0-or-later` is taken as **MIT**. No concerns.

### Rust / Cargo (Tauri) — 524 crates

Overwhelmingly `MIT OR Apache-2.0` (244) and MIT (116), plus Apache-2.0, BSD,
Zlib, Unicode-3.0, MPL-2.0, ISC — all permissive. The only AGPL entry is our own
`soundchex` crate. No concerns.

## The one thing that is NOT a dependency-license question: FFmpeg

FFmpeg is not a linked dependency — the app **shells out** to a user-installed
binary today (`config/transcode.php`, `MediaTranscoder`). When it is *bundled*
(S-151, Step 8), the plan is a full GPL ffmpeg, which is fine **because we are
AGPLv3** (GPL-compatible). Recorded here so the ffmpeg decision isn't confused
with the dependency audit. See [[soundchex-license]] and `BundledServer.md`.

## What "legal review" still means (separate task)

The policy documents (`SoundChex Website` `/legal`) carry "draft pending legal
review". That review is a **qualified attorney** reading the terms/privacy docs
and the AGPLv3 + relay-only patent posture, confirming they are correct,
enforceable and non-exposing — not something a license scan replaces. To clear
it:

1. Engage an attorney (IP / tech / privacy).
2. Have them review: the legal suite, the AGPLv3 choice, the relay-only H.264
   patent posture (our servers never encode), and DMCA-agent registration before
   SCNet carries any user traffic (already tracked as website W-14).
3. On sign-off, remove the "draft pending review" banners.

This audit + `BundledServer.md`'s licence section are the brief to hand them.

## To re-run this audit

- PHP: `composer licenses --format=json`
- npm: read `license` from each `node_modules/*/package.json`
- Cargo: `cargo metadata --format-version 1` (or install `cargo-license` /
  `cargo-deny` for a maintained gate).

Re-run after any dependency change, and before the first bundled-server release
(to generate `THIRD-PARTY-LICENSES.txt`).
