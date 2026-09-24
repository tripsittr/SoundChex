# 192 — Cleanup pass over the plugin platform and events

**Merged** 2026-09-24 · **Issues** S-367

Fifth simplifier pass. Earlier ones covered Services, Filament, Http, Console,
Models and Jobs; this one took `app/Plugins`, `app/Events`, `app/Support`,
Providers, Policies, Observers and Enums — none of which had been reviewed.

Three real bugs, all in the same posture: they surface when a third-party
plugin or repository misbehaves, which is exactly what the platform exists to
survive.

## What changed

### A malformed catalog checksum crashed instead of refusing

`PluginInstaller::verifyChecksum()` split `"algo:hash"` out of catalog JSON and
handed the algorithm straight to `hash()`. PHP raises a `ValueError` for an
algorithm it does not know — and that is not a `PluginInstallException`, so a
repository serving `"sha-256:…"` or `"crc32c:…"` escaped the install UI as a
500 rather than a refusal an admin can read.

This is the one place the server ingests untrusted network input, and the
class promises in its own doc-block that every failure throws
`PluginInstallException` with a reason the UI can show. The algorithm is now
checked against `hash_algos()` first.

### defineEvent() threw away its description

The `$description` parameter was accepted, documented in the plugin docs, and
passed by an existing test — then dropped on the floor, because `pluginEvents`
stored only the plugin id. The docs promise that declaring an event "makes it
discoverable rather than something another author has to know about by reading
source"; the only discoverable thing was the bare name.

Descriptions are kept now, with a `definedEvents()` accessor exposing both.
`availableEvents()` reads `array_keys()`, so it is unaffected.

### A plugin that failed to boot was not named

`bootLoaded()` caught `Throwable` and called `report($e)` and nothing else. Its
sibling `load()` logs the plugin id; this did not — so a plugin throwing in
`boot()` produced a trace whose frames are all inside the plugin's own
namespace, and an operator could not tell which plugin to disable. Same defect
for a broken plugin routes file, which pointed only at the file. Both now log
the plugin id, with `report()` kept so error reporting is unchanged.

### Consistency

- Three events (`MediaItemEnriched`, `MediaItemCatalogued`, `PlaybackRecorded`)
  used `Dispatchable` directly while the other 38 use the `PluginEvent` trait.
  The trait is `use Dispatchable;` and nothing else, so this is
  behaviour-identical — but the trait marks the family so the catalogue and
  docs can find them, and these three sat outside that marker.
- Three Registry seams declared their backing property *after* the methods
  using it, unlike the nine that declare it before.
- Inline FQCNs replaced with imports in files that import everything else.

### Two comments had gone stale

Flagged explicitly, since comments here are load-bearing:

- `PluginServiceProvider` claimed "bundled plugins load regardless; installed
  ones wait for the table". S-321 removed always-on bundled plugins entirely —
  everything is gated on the install table now.
- An orphaned doc-block in `PluginLoader` sat above `ensurePluginsDirectory()`'s
  own doc-block while describing `manifestsIn()`, which had none. Moved to the
  method it describes.

## Worth knowing

- No behaviour changes beyond the three fixes. No migration, no config.
- Pint was **not** run, per the project rule.
- `PluginInstaller::manifestFromZip()` says it takes "the shallowest match", but
  `locateName(..., FL_NODIR)` does not guarantee that. Latent inaccuracy rather
  than a bug — the nested fallback only runs when the root lookup fails — and
  tightening it would change which manifest wins for a multi-manifest zip. Left
  alone as a behavioural change; worth its own tracker entry.

## Still wrong

`Registry::apply()` and `renderSlot()` still swallow throwables. That is
deliberate and documented — one plugin's bad filter must not break the value
for everyone downstream — and was left as-is.

## Tests

PHP · 949/954, 4 skipped. Three new tests, one per bug: the checksum guard
(verified to fail with a raw `ValueError` when the fix is removed), the
description being kept, and the existing plugin suite at 108/112.

The one failure, `ListPageCostTest::test_an_album_page_does_not_query_per_track`,
is unrelated and reproduces on a clean tree — S-362.
