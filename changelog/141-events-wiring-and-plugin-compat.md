# 141 — Event wiring completed, and a plugin-version-survival contract

Builds on the event surface (140). Two things: more of the catalogued events now
actually fire from where the action happens, and plugins gained a defined,
narrow rule for when a server update is allowed to break them.

## Events now wired to their dispatch points

The catalog registered 40 events in 140; several were still inert (no emit site).
These now fire:

- `metadata.titleTidied` and `cover.embedded` — from `EnrichMediaItemJob`, when a
  title is rewritten during enrichment and when a cover is baked into the file.
- `device.signedIn` / `device.signedOut` — from `Api/TokenController`, on token
  issue and revoke.
- `profile.switched` — from `CurrentProfile::switchTo()`.
- `user.searched` — from `MediaCenterController::search()`, carrying the term,
  result count, and profile.
- `duplicate.merged` (×2 success paths) and `duplicate.resolved` (×2) — from
  `DuplicateDetector`.
- `playlist.created` / `playlist.deleted` — from `PlaylistController`.

Still deliberately un-wired (need new plumbing — a `MediaItemObserver` — or are
lower value): `media.added`, `media.deleted`, `profile.created/deleted`,
`transfer.started`, `upload.completed`, `user.rated`, `playback.progress`,
`cover.fetched`. They stay registered and inert until wired in a follow-up; a
listener on one is harmless.

## Plugins survive server versions unless a major overhaul

New contract so an installed plugin keeps working across app updates:

- A separate **plugin-API version** (`config('soundchex.plugin_api_version')`,
  starting at `1.0.0`), distinct from the app version. It only moves when the
  plugin surface (events, seams, `Registry`) changes: new events/seams are MINOR
  bumps; a breaking overhaul is a MAJOR bump.
- Manifests gain a `targetApi` field recording the API version the plugin was
  built against. `plugin:make` scaffolds it with the current value.
- `PluginManifest::isCompatibleWith()` / `incompatibilityReason()` now take the
  plugin-API version too. A plugin loads as long as it shares the server's
  plugin-API **major**. It is refused only when the app has moved to a new
  plugin-API major (the "major overhaul" case) or when the plugin targets a newer
  API than the server ships. A manifest with no `targetApi` still loads.
- The loader and the catalogue installer both gate on this; the loader logs the
  reason a plugin was skipped.

The five bundled/example manifests and the `plugin:make` scaffold all carry
`targetApi: "1.0.0"`.

## Docs

`docs/plugins/README.md`: added the `targetApi` manifest row and a "Surviving
server versions" section spelling out the major-line guarantee.

## Tests

- `PluginVersionSurvivalTest` (new, 10 cases): same major survives, major
  overhaul refused, plugin-ahead refused, no-`targetApi` still loads, server
  floor still applies, refusal reason explains the overhaul.
- `PluginLoaderTest` updated to the 3-arg compatibility signature.
- Full plugin suite (76) and the affected controller/service suites green.

## Not done here

The remaining inert events above, and the `MediaItemObserver` that
`media.added`/`media.deleted` need, are follow-up work.
