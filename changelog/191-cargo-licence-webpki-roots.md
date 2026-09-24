# 191 — The Cargo licence gate passes again

**Merged** 2026-09-24 · **Issues** S-365

`cargo deny check licenses` has been failing on `main` since at least
2026-09-23, so every pull request has shown a red **Cargo licences** check and
the gate stopped meaning anything.

## What changed

`webpki-root-certs` ships Mozilla's root certificate set under
**CDLA-Permissive-2.0**, which was not in the allow list. It arrives
transitively: `rustls-platform-verifier` → `reqwest` → `tauri`.

The licence is fine for us. CDLA Permissive 2.0 grants unrestricted use and
redistribution of the *data* with no copyleft reaching the software that uses
it, so it does not constrain distributing SoundChex under the AGPL.

Added as a **per-crate exception** rather than a global allowance — a data
licence is the right answer for a certificate bundle and the wrong one for a
code dependency, and `deny.toml` already had the mechanism for exactly this.

## Worth knowing

- Verified locally with `cargo deny check licenses` (`licenses ok`), which
  meant installing `cargo-deny` — it was not present on this machine.
- The remaining output is `license-not-encountered` **warnings** for allowances
  nothing currently uses (`OpenSSL`, `Unicode-DFS-2016`, the GPL entries).
  Those are not failures and were there before.
- Worth reflecting in the licence audit and the site's credits when that is
  next revisited: this is a new third-party licence in the distribution.

## Still wrong

Nothing here. Unrelated: `ListPageCostTest::test_an_album_page_does_not_query_per_track`
still fails on `main` (S-362).

## Tests

`cargo deny check licenses` passes locally. No PHP or JS touched.
