# 142 — The event catalogue, fully wired

Finishes what 140/141 started: every event in the plugin catalogue that has a
place to fire from now fires from it. What is left un-wired is left so on
purpose, and named.

## Newly dispatched

- **`playback.progress`** — from both progress endpoints (web
  `MediaCenterController@saveProgress` and API `Api/MediaController@saveProgress`),
  on every position save. Distinct from `playback.completed`, which still fires
  once at the completion crossing.
- **`media.added`** — from `EnrichMediaItemJob`, once enrichment has finished and
  the item has settled into the library as a real, kept item. Deliberately
  distinct from `media.catalogued` (fired the instant a file is first seen) and
  `media.enriched` (fired at the same point but meaning "enrichment ran").
- **`transfer.started`** — from `RunTransferJob`, on the first transition into
  running (a resumed transfer already has a start time, so it fires once).
- **`user.rated`** — from `Api/AdminController@updateItem`, only when a rating was
  actually part of the edit; a title-only save does not fire it. Carries the
  active profile.
- **`cover.fetched`** — from `RefetchCoversJob`, when a verified cover is found
  and stored for an item.

## Model observers

`profile.created` / `profile.deleted` and `media.deleted` are model-lifecycle
events, so they are fired from observers rather than each call site — every path
that creates or removes the row emits them, including the Filament admin, the
on-demand default profile, a duplicate merge, and any future bulk tool.

- `App\Observers\ProfileObserver` → `profile.created`, `profile.deleted`
- `App\Observers\MediaItemObserver` → `media.deleted`

Registered with `#[ObservedBy(...)]` on the models. `MediaItemObserver`
deliberately has no `created` handler — a row appearing is `media.catalogued`,
and the "settled, kept item" milestone is `media.added` from the enrich job.
Firing on model-create would collapse three distinct lifecycle points into one.

## Left un-wired, on purpose

- **`upload.completed`** — no bulk-upload endpoint exists in the app (a
  self-hosted server adds media by dropping files into a watched folder and
  scanning, not by uploading). The event stays registered with its contract
  documented, to be wired if that feature is ever added.

## Tests

- `EventWiringFinishTest` (9): each new dispatch fires; a title-only edit does
  not fire `user.rated`; `media.deleted` fires however the row leaves; the whole
  set is in the catalogue.
- Full suite green (825).

## Incidental fix

The `year-tagger` example plugin's files had gone missing from the working tree
(still tracked in git); restored from HEAD, which un-broke `ExamplePluginTest`
and two `PluginsPageTest` cases. No code change — the files were simply put back.
