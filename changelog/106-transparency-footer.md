# 106 — A transparency footer in the served app

*2026-09-18.*

## Done

The served app's footer was one line ("Self-hosted media library"). Expanded it
into a **transparency footer** so the whole project is reachable from inside the
app — not only from a website a self-hoster may never have seen.

- A plain statement of what SoundChex is and that it stores none of your media
  off your own machine.
- Links to the **source (AGPLv3)**, the **licence**, **privacy**, **terms**,
  and the **open-source credits** — plus the website when one is configured.
- Copyright/trademark line and a note that a commercial licence is available.
- `config('app.links')` (`SOUNDCHEX_WEBSITE_URL`, `SOUNDCHEX_REPO_URL`): legal
  links point at the marketing site when its domain is set (W-11), else the
  public GitHub repo, which is durable.

## Tests

Two: the footer links source/licence/privacy/terms/credits; the legal links
follow a configured website URL. 45 media tests pass.

## Follow-up

The same transparency links need adding to the native iOS app (a Settings/About
section), and the marketing site's real domain (W-11) will point the legal
links off GitHub.
