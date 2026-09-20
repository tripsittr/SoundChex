# 129 — Plugin platform, Phase 3: events and filters (S-264)

The reconnaissance found SoundChex had no domain events at all — a plugin could
add a metadata source but could not *react* to anything the server did. This
phase introduces the hook layer: named events a plugin subscribes to, and
filters a plugin uses to change a value passing through the app. This is what
takes plugins from "add data" to "extend behaviour".

## Named events

Three domain events, fired at the moments that matter, each subscribed to by its
stable friendly name so a plugin never has to know the class:

- **`media.catalogued`** (`MediaItemCatalogued`) — a file has entered the
  library, before enrichment runs.
- **`media.enriched`** (`MediaItemEnriched`) — the pipeline and its follow-up
  steps have finished for an item.
- **`playback.recorded`** (`PlaybackRecorded`) — a play was recorded, carrying
  the item and the profile — the hook a Last.fm/Trakt scrobbler needs.

A plugin subscribes with `$registry->on('media.enriched', fn ($event) => …)`.
The name→class map means a class can be renamed without breaking a plugin, and
an unknown name passes through so an author can still listen to any Laravel event
by class. `Registry::availableEvents()` lists them for the docs and admin UI.

## Value filters

The WordPress-filter idea on Laravel: the app calls `apply()` at a named point
with a value, and each registered filter transforms it in turn. Unlike an event
(which reacts), a filter *changes* the thing passing through.

- `$registry->filter('metadata.title', fn ($title, $item) => …)` registers one.
- The app runs a value through them with `$registry->apply($hook, $value, …$ctx)`,
  which returns the value untouched when nothing is registered — so a caller can
  always apply a hook without checking.
- A filter that throws is logged and skipped; one plugin's bad filter cannot
  break the value for everyone downstream.

The first live filter point: **`metadata.title`**, applied to a track's final
title during enrichment, so a plugin gets the last word after the built-in
artist-stripping.

## Notes

- Firing the events is inert with no listeners, so nothing changes for an install
  with no plugins — every existing test still passes untouched.
- The remaining Phase-3 seams named in the proposal (cover/subtitle source
  registries, plugin routes, the per-plugin settings page) are deferred to keep
  this a focused, reviewable unit; the event + filter layer is a complete one.

## Tests

- `PluginHooksTest` — a plugin subscribes to an event by friendly name and
  receives it; a real scan fires `media.catalogued` end-to-end to a listener;
  the named events are advertised; filters transform and chain in order, return
  the value untouched when unregistered, skip a throwing filter, and receive
  their context.
- Full suite: 755 passed.

## Next

Phase 4 — the admin *Plugins* manager page (installed list, enable/disable,
settings) and the Emby-style install catalog.
