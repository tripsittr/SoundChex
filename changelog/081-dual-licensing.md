# 081 — Dual licensing: AGPLv3 + commercial (S-156)

**Merged** 2026-09-17 · **Issues** S-156

SoundChex is now dual-licensed. The default stays AGPLv3 (free); a commercial
licence is available for those who cannot or will not comply with the AGPL.

## What changed

- **`LICENSING.md`** — explains the two options, a which-do-I-need table, and the
  commercial contact (`licensing@soundchex.app`). AGPLv3 remains the default and
  the `LICENSE` file is unchanged.
- **`CLA.md`** — a Contributor License Agreement. This is the load-bearing piece:
  it keeps Tripsittr LLC's right to relicense contributions, which is what makes
  dual licensing *possible*. Without it, an outside contribution could not be
  included in the commercial licence.
- **`COMMERCIAL-LICENSE.md`** — a draft describing the shape of a commercial
  agreement (scope, term, fee, what it does/doesn't cover). Clearly marked as a
  draft; the binding contract is a signed agreement.
- **README** licence section rewritten to state the dual model.
- **`.gitignore`** — the repo ignores `*.md` by default and whitelists specific
  files; added the root licensing docs (and REPOS.md) to the whitelist so they
  are tracked and visible.

## Worth knowing

- **Prerequisite:** dual licensing only works because Tripsittr LLC owns / can
  relicense all first-party code — true today. The CLA protects that going
  forward. Third-party dependencies are **not** relicensed; they keep their own
  (permissive) licences (S-155 audit).
- All commercial/CLA wording is **draft pending legal review** — the same status
  as the policy documents. A real commercial agreement, a CLA-enforcement
  mechanism, and the `licensing@` inbox are follow-ups (S-156).
