# 127 — Plugin platform, Phase 1: the contract and loader (S-264)

The first phase of the plugin platform (S-264) — an extensive, Filament/Emby-
class system anyone can build on with docs. This phase is the engine: a plugin
can now be dropped on disk, enabled, and have its code loaded and its
contributions collected. There is not yet anything user-facing to extend — that
arrives in the phases that follow — but the whole loading pipeline is real and
tested.

Approved architecture: **Emby-style install over a Filament-style engine**. A
plugin is a directory with a manifest (installable without a deploy); internally
each implements a Filament-style `getId / register / boot` contract resolved
through the container.

## What this adds

- **`SoundChexPlugin` contract** — the one interface a plugin's entry class
  implements, modelled on Filament's own `Plugin`: `getId()`, `register()`,
  `boot()`.
- **`Registry`** — the object a plugin pushes contributions onto. Phase 1 ships
  the two seams honourable today: metadata sources (the pipeline reads these in
  Phase 2) and named events. Every contribution is attributed to a plugin id, so
  a disable can drop it. Later seams (routes, cover sources, admin pages) become
  methods here without changing the contract.
- **`PluginManifest`** — a parsed, validated `plugin.json`: identity, entrypoint,
  `provides`, and the compatibility gate (`minSoundChexVersion` / `requiresPhp`,
  the equivalent of Jellyfin's `targetAbi`). Rejects a missing field or an id
  that is not a usable slug.
- **`PluginLoader`** — discovers plugin directories, reconciles them against the
  install table, gates on enabled + compatibility + a master switch, registers
  each plugin's namespace with **Composer's autoloader at runtime** (no
  `composer dump-autoload`, so a plugin added from the UI loads on the next
  request), instantiates the entry class through the container, and runs its
  lifecycle. Every failure is logged and swallowed — one bad plugin can never
  break the boot.
- **`installed_plugins` table + model** — the app's record of what is installed
  and, crucially, what is *enabled*. New plugins land **disabled**; enabling is a
  human decision and a re-scan never changes it.
- **`PluginServiceProvider`** — runs discovery at boot (guarded so a boot before
  the table exists is safe), and defers each plugin's serving-time `boot()` to
  actual requests, off console and queue.
- **`config/soundchex.php`** — the authoritative server version (SemVer, for
  gating) and the plugins path + master switch.
- **`plugin:list`** — the CLI view of installed plugins and their state
  (`--rescan` to pick up folders added by hand). The admin UI is Phase 4.

## Trust posture (as approved)

Matches the industry floor: a master switch, per-plugin enable/disable, version
gating, and defensive loading. A plugin is still arbitrary PHP in the app
process — no ecosystem sandboxes plugin code, and real isolation is tracked as a
separate future item, not built here.

## Tests

- `PluginLoaderTest` (11) — discovery lands a plugin disabled; a re-scan never
  flips enabled state; an enabled plugin loads and its registration reaches the
  registry; disabled / master-switch-off / version-incompatible plugins do not
  load; both lifecycle halves run; runtime PSR-4 autoloading resolves the
  plugin's own classes (not loadable before the loader ran, loadable after);
  the manifest rejects bad input and gates on version.
- Full suite: 743 passed.

## Next

Phase 2 moves the existing metadata sources onto this contract — the first real,
dogfooded extension point — which also closes S-39 (the extra sources ship as
plugins rather than core).
