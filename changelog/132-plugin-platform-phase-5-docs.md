# 132 — Plugin platform, Phase 5: the scaffold and docs (S-264)

The last phase, and the one that makes the platform matter: the adoption lever.
An author can now scaffold a working plugin in one command and has a full guide
to build it out — "fully developable by anyone with docs", the mandate for
S-264. This closes the plugin platform.

## What this adds

- **`plugin:make`** — scaffolds a complete, valid, loadable plugin, not a blank
  file (the Filament `configure.php` / Jellyfin `dotnet new` idea). One command
  writes the manifest, the entry class (`getId`/`register`/`boot`), and a sample
  metadata source, with the namespace derived from the id. The result appears in
  the admin the moment it is enabled and registers its source end to end — an
  author edits something that already runs.

- **The developer guide** (`docs/plugins/README.md`) — a two-minute quickstart,
  the entry class, a full manifest field reference, an extension-point cookbook
  (metadata sources, the three events, the filters, each with the real table of
  what is available), how to distribute a plugin (drop-in folder or a repository
  catalogue with `targetAbi`/`checksum`), and the trust section stated honestly:
  no sandbox, match the floor, install only what you trust.

## The platform, complete

S-264 asked for an extensive, Filament/Emby-class plugin system anyone can build
on with docs. Across five phases:

1. the contract and loader (runtime autoloading, version gating);
2. metadata sources as the first dogfooded seam (closed S-39);
3. the event and filter hook layer;
4. the admin manager and (4b) the Emby-style install catalogue;
5. the scaffold and docs.

A plugin author writes a manifest and an entry class, scaffolded in one command;
a user installs from a repository in the admin UI, or drops a folder in; every
install is integrity- and compatibility-checked and lands disabled; and the whole
thing is documented. The trust posture is the industry floor, stated plainly at
the point of the decision, with real sandboxing left as a future differentiator.

## Tests

- `PluginMakeTest` — the scaffold writes a valid manifest and entry class; the
  scaffolded plugin loads and registers its source end to end (its class
  autoloads at runtime); a bad id is rejected; it refuses to overwrite.
- Full suite: 774 passed.
