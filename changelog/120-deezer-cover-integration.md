# 120 — Deezer as a cover-art integration

*2026-09-19.* · **Issue** S-259

## Done

A second cover-art source, **Deezer** — free, keyless, and **opt-in** — surfaced
on the Integrations page as a toggle rather than a key.

- **Enrichment:** the `Deezer` source (registered in `metadata_sources.php`)
  fills a track's cover when no higher source did, searching Deezer track-level so
  a compilation-tagged album still finds the real recording's art. It matches on
  the **primary artist** and accepts a result only when the artist matches —
  better no cover than the wrong one.
- **Artwork refresh:** `CoverArtFetcher` now falls back to Deezer (album search)
  when iTunes misses, so `artwork:refresh` benefits too. Same per-album efficiency
  and validation.
- **Integrations page:** Deezer is a **keyless toggle** in the Artwork group — a
  new integration type alongside the key-based providers. "Set up" enables it,
  "Unlink" turns it off; nothing to type.
- **Off by default** — a second source is opt-in — and gated everywhere through
  the `deezer_enabled` setting, so nothing calls Deezer until it is switched on.

## Worth knowing

- Turn it on under **Integrations → Deezer**, then re-enrich or run
  `artwork:refresh` to have it fill covers iTunes couldn't.
- Keyless-toggle integrations are new: the Integrations page now carries three
  kinds — acquisition apps (address + key), metadata providers (key), and plain
  on/off toggles. Discord/Slack and notification hubs will follow the same toggle
  or webhook shape.

## Tests

PHP: **686 passing** (+8). `DeezerCoverTest`: off unless enabled; sets a verified
cover; rejects a wrong-artist result; never overwrites an existing cover; searches
by the primary artist. `IntegrationsPageTest` gains the toggle: Deezer is listed;
"Set up" enables it without a key; "Unlink" disables it. Pint clean.
