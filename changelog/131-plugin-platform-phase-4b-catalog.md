# 131 — Plugin platform, Phase 4b: the install catalog (S-264)

Plugins can now be found and installed from the admin UI, without a shell — the
Emby/Jellyfin repository model. A repository is a JSON catalogue at a URL; the
server fetches it, shows what can be installed, and installs a chosen plugin
after verifying it.

## What this adds

- **Repositories.** A `plugin_repositories` table of catalogue URLs, browsed
  from the Plugins page. The official one is marked trusted; others are added at
  the admin's own risk — which the page states. Being listed is not trust, only
  a place to fetch a catalogue.
- **`PluginCatalog`** — fetches a repository's manifest (an array of plugins,
  each with a `versions` list) and narrows each to the **newest version this
  server can run**, so an incompatible plugin is shown as such rather than
  offered and then failing. An unreachable or malformed repository yields an
  empty list, never an error into the request.
- **`PluginInstaller`** — the one place the server pulls third-party code, so the
  checks live here:
  - **checksum** verified on the download (`sha256:…` or a bare hash) — integrity
    against a corrupted or tampered-in-transit file; a mismatch refuses the
    install;
  - the zip's **manifest** is read and its **compatibility** re-checked before a
    single file is written;
  - extraction is **zip-slip-guarded** — any entry whose path escapes the plugin
    directory refuses the whole install;
  - the result is an installed plugin, still **disabled** — installing is not
    enabling.
- **On the Plugins page** — add/remove repositories, refresh the catalogue,
  install with a confirmation, and an already-installed plugin is not offered.

## Trust posture (as approved)

This is the industry floor, made concrete: a curated official repository, an
explicit at-your-own-risk note for third-party ones, checksum on download,
compatibility gating, and install-disabled-then-enable. No sandboxing — a plugin
is still arbitrary code — which the enable step states plainly.

## Tests

- `PluginCatalogTest` — the catalogue picks the newest compatible version; an
  all-too-new plugin is listed but not installable; an unreachable repository is
  empty; a valid plugin installs disabled; a **checksum mismatch is refused**; a
  **zip-slip archive is refused and nothing escapes**; an incompatible download
  is refused.
- `PluginsPageTest` — browsing lists a repository's installable plugins; adding a
  repository records it; an invalid URL is rejected; an already-installed plugin
  is not offered.
- Full suite: 770 passed.

## Next

Phase 5 — the developer docs and a plugin scaffold, the adoption lever that lets
anyone build one.
