# 134 — TitleTidier becomes a bundled plugin (S-264, #279)

The app's own title cleanup — stripping a track's artist out of its title
("Gold - Imagine Dragons" → "Gold") — was hardcoded in the enrichment job. It is
now a plugin. This is the platform dogfooding itself: a core opinion, moved onto
the same seam a third party would use, with no change in behaviour.

## Bundled plugins

A new class of plugin: **first-party, always on**. Bundled plugins live in the
repo under `plugins/bundled/`, load *enabled* without going through the
install/enable flow, and are the app's own behaviours written as plugins. They
are still compatibility-checked, still fail safe, and the master switch
(`SOUNDCHEX_PLUGINS_ENABLED=false`) still turns them off — but there is no toggle,
because they are the app.

The loader now loads bundled plugins before installed ones, and loads them even
before the `installed_plugins` table exists (a fresh migrate, a bare test), since
they do not depend on it.

## The conversion

- **Title Tidier** (`plugins/bundled/title-tidier`) registers a `metadata.title`
  filter that runs the existing `TitleTidier` service against a music track's
  own artist — the same logic, reused, not rewritten.
- The enrichment job's `tidyTitle()` no longer strips the artist itself; it hands
  the title to the `metadata.title` filter chain and takes back the result. The
  bundled plugin is the first link in that chain, any admin-added title plugin
  the rest.

Behaviour is identical for existing libraries: the strip still happens, at the
same point, to the same tracks.

## Tests

- `BundledPluginTest` — a bundled plugin loads with no install row; the Title
  Tidier plugin strips a track's artist; the master switch turns bundled plugins
  off too.
- Full suite: 785 passed (including the existing artist-strip enrichment test,
  now satisfied by the plugin).

## Next in this arc

#280 (cover sources) and #281 (notification targets) each need a new registry
seam first; #282 (built-in metadata sources as plugins) uses the existing seam.
