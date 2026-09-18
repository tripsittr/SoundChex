# 091 — GitHub community health files

*2026-09-18.*

## Done

Added the standard GitHub community-health files at the repo root, so the
project presents properly to outside contributors and reporters:

- **CODE_OF_CONDUCT.md** — Contributor Covenant 2.1, with a `conduct@soundchex.app`
  contact.
- **SECURITY.md** — private vulnerability reporting (GitHub advisories +
  `security@soundchex.app`), a good-faith safe-harbor, response-time
  expectations, and a scope tuned to a self-hosted media server (path traversal,
  SSRF, auth/tier boundaries, SCNet relay isolation).
- **CONTRIBUTING.md** — the real SoundChex workflow: read AGENTS.md, the CLA
  applies on PR (dual-licensing), issue-first, branch off main, tests + Pint,
  a changelog entry per PR, no committed media, no AI artifacts.

## Notes

Contacts use the existing `soundchex.app` convention (cf. `licensing@soundchex.app`
in LICENSING.md). Consider enabling GitHub's private vulnerability reporting and
Dependabot in the repo settings — SECURITY.md points at the former.
