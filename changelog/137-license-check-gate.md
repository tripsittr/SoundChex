# 137 — The dependency-licence gate (S-5)

The audit confirmed every dependency is AGPL-compatible and the
`THIRD-PARTY-LICENSES` file was written; the one piece left was the **gate** that
keeps it that way. Now a PR that pulls in a dependency whose licence forbids AGPL
distribution (SSPL, BUSL, GPL-2.0-only with no permissive option, proprietary,
unlicensed) fails automatically rather than shipping.

## What this adds

- **`php artisan licenses:check`** — the Composer gate, runnable locally and in
  CI. The rule is per package, not per licence string: a package passes when it
  offers **at least one** AGPL-compatible licence, even if it also lists an
  incompatible one — most GPL-flagged packages here are multi-licensed and also
  offer BSD or LGPL. It fails only when a package offers nothing compatible, or
  nothing at all. Checked the real tree: 147 dependencies, all pass.
- **`src-tauri/deny.toml`** — the Cargo gate for `cargo deny check licenses`, an
  allow-list of the AGPL-compatible licences the audit accepted.
- **`.github/workflows/license-check.yml`** — runs all three ecosystems on every
  PR and push to main: Composer via the artisan command, Cargo via cargo-deny,
  npm via `license-checker` against the same allow-list. Verified locally that
  the current npm and Composer trees pass.

## Why per-package, not per-licence

`composer licenses` reports `nette/utils` as `BSD-3-Clause, GPL-2.0-only,
GPL-3.0-only` — a naive "reject GPL-2.0-only" gate would fail it falsely, when
the package is fine under BSD. The gate mirrors how the licence actually works:
a package offering any compatible option may be used. That is also why the same
tree the audit called clean passes the gate rather than needing an exception
list.

## Tests

- `CheckLicensesTest` — passes a clean tree; passes a multi-licensed package on
  its compatible option; **fails** a GPL-2.0-only dependency with no
  alternative, an SSPL dependency, and an unlicensed one. The failing cases are
  the point — a gate that never fails is no gate.
- Full suite: 797 passed.

## Note

ffmpeg is bundled, not linked (the app shells out to it); its licence is handled
with the bundled-server release (S-151), not here. Legal *review* of the policy
documents remains a human-attorney task (website W-14 / S-231).
