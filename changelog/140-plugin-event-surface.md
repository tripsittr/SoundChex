# 140 — A large plugin event surface, and custom plugin events (S-276)

The plugin platform had three observable events. This turns that into a broad
catalogue — **40** events across every domain — so a plugin can react to
essentially anything meaningful the app does, and lets a plugin **define and fire
its own events** that other plugins observe. Closes S-276 (the download/transcode/
new-media/health events it asked for) as part of a much larger surface.

## The catalogue

40 events, grouped: media (catalogued, enriched, added, deleted, review-flagged),
scan (started, finished), episode, transcode (started, finished, failed), cover
(fetched, embedded), duplicates (detected, merged, resolved), playback (recorded,
progress, completed), notifications, transfers (started, completed, failed),
devices (signed-in, signed-out, reported), profiles (created, switched, deleted),
watchlist (added, removed), user actions (searched, rated), metadata title-tidy,
uploads, and server (health-checked, extension-missing).

A plugin subscribes by stable name: `$registry->on('transcode.finished', fn ($e)
=> …)`. Every event is a small `readonly` value object; `Registry::builtInEvents()`
lists them, and the plugin docs carry the full table.

## Dispatches wired this pass

The highest-value lifecycle points now fire their event (the rest of the
catalogue is registered and subscribable; their dispatches land in follow-ups):

- `notification.recorded` — the single choke point every in-app notification
  passes through, so one subscription catches them all.
- `playback.completed` (web + API) and **the API `playback.recorded` gap** — the
  native app's plays fired no event while the web player's did, so a scrobbler
  saw web listens and not app ones. Fixed.
- `scan.started` / `scan.finished` (with counts), `episode.added`.
- `transcode.started` / `finished` / `failed`.
- `duplicate.detected`, `media.reviewFlagged`, `device.reported`,
  `watchlist.added` / `removed`, `transfer.completed` / `failed`.

## Custom plugin events

A plugin can now expose events of its own:

- `$registry->defineEvent('acme.export.finished', '…')` declares it (joins
  `availableEvents()` so other authors discover it).
- `$registry->emit('acme.export.finished', $payload)` fires it.
- another plugin `$registry->on('acme.export.finished', …)` observes it.

So plugins compose — one plugin's work becomes another's trigger — without the
app knowing the event name ahead of time.

## The health check

`server:health` (scheduled hourly) gathers disk free, queue depth, failed jobs
and missing PHP extensions, fires `server.health` (and `server.extensionMissing`),
and records a notification when something is actually wrong — so an unhealthy
server reaches every notifier, and a monitoring plugin can react. There was no
periodic health signal before, only an on-demand endpoint.

## Notes

Firing an event is inert with no listener, so a plugin-less install is unchanged
— every existing test still passes untouched.

## Tests

- `PluginEventSurfaceTest` — the catalogue is large and includes the new events;
  notification and scan dispatches fire and reach a subscriber; a plugin's custom
  event is emitted and observed by another; a defined event joins the catalogue
  without polluting the built-ins; emitting with no subscribers is harmless.
- `ServerHealthCheckTest` — the check emits `server.health`, stays quiet when
  healthy, and notifies when there are failed jobs.
- Full suite: 806 passed.
