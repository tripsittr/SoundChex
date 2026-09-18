# 090 — AGPLv3 §13: offer the source to network users

*2026-09-18.*

## Done

A compliance gap: **AGPLv3 §13** requires that anyone who interacts with
SoundChex *over a network* be offered its corresponding source — and the served
app UI linked to the source nowhere.

- **Login page** (pre-auth, the first network-facing surface) now carries a
  discreet "SoundChex is open source (AGPLv3)" link to the repository.
- **Account menu** on every media page gains an "Open source (AGPLv3) — source
  code" item.
- `AgplSourceOfferTest` guards the login-page offer so it can't silently regress.

## Notes

Part of a wider licensing/ToS/privacy compliance pass. The website legal-copy
findings (the "no third-party requests" claim vs the /download → GitHub links,
the missing commercial-license mention, undisclosed bundled third-party runtime,
absent Arizona jurisdiction clause) are tracked separately in the website repo.
