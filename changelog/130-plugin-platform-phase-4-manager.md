# 130 — Plugin platform, Phase 4: the admin manager (S-264)

Until now a plugin was enabled by editing a database row. This adds the admin
surface: a **Plugins** page under System where an admin sees what is installed,
reads who wrote each one and what it touches, and turns it on or off.

## What this adds

- **A Plugins manager page.** One card per installed plugin: name, version,
  author, description, the seams it `provides`, its licence, and its enabled
  state. **Re-scan** picks up a plugin folder added by hand.
- **Enable / disable from the UI.** Enabling is the deliberate act that lets a
  plugin's code run, so it is gated behind a confirmation that spells out what
  that means, and a plugin always arrives disabled. A change takes effect on the
  next request — plugin code is wired at boot — and the page says so rather than
  pretending a running plugin vanished mid-request.
- **The trust note, up front.** The page leads with a plain statement that a
  plugin runs as part of SoundChex with full access, and to enable only plugins
  from an author and source you recognise — the "match the floor, state it
  plainly" posture, made visible at the exact moment of the decision.

## Scope

This is the manager. The Emby-style **install catalog** — a repository URL
serving a manifest, browsed and installed in-app with checksum and compatibility
checks — is its own body of work (network I/O, download, unzip, a real security
surface) and ships as Phase 4b, so this stays a focused, reviewable unit. The
manager already covers the everyday case: a plugin folder dropped in, seen, and
enabled.

## Tests

- `PluginsPageTest` — the page discovers and lists the bundled example (arriving
  disabled); enabling flips its state; disabling flips it back; an empty state
  shows when the directory holds nothing.
- Full suite: 759 passed.

## Next

Phase 4b — the install catalog. Then Phase 5 — the developer docs and a plugin
scaffold.
