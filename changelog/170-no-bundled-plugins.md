# 170 — No bundled plugins: every plugin is installed and enabled

The plugin platform had two classes of plugin: always-on **bundled** ones that
shipped in the app and loaded regardless, and **installed** ones gated on an
enable toggle. That split caused real confusion — a bundled plugin's page showed
even when a same-id installed copy was "disabled", and its toggle did nothing.

There is now only one kind of plugin (S-321): every plugin is installed from a
repository and enabled by the operator. A fresh install has **no** plugins until
one is installed.

## Changed

- **Removed the bundled concept from the loader.** `PluginLoader` no longer loads
  from a `bundled_path`; the enable gate is unconditional, so a plugin loads only
  when it has an enabled install row. The `bundled_path` config is gone.
- **The three first-party plugins left the app repo.** The activity log, title
  tidier and playlist porter are now standalone plugins in their own
  repositories, installed through the catalogue like any other. They no longer
  live in `plugins/bundled/`, and their tests moved with them.
- **An official repository can be seeded.** A new `plugins.official_repository`
  config (env `SOUNDCHEX_PLUGIN_REPOSITORY`) and `OfficialPluginRepositorySeeder`
  register the project's curated catalogue, so a fresh install can discover and
  install the first-party plugins. Users can add their own repository URLs
  alongside it — a plugin author can host their own repository and needs no entry
  in the official one.

## Effect on a fresh install

Nothing plugin-provided is present by default — no audit log, no title tidier, no
import page — until the operator installs and enables it. The app runs correctly
with zero plugins.

## Testing

- The platform suites (loader, catalogue, self-contained seams, plugins page)
  pass (22): the loader boots clean with zero plugins and registers nothing until
  a plugin is installed and enabled.
- The plugin-specific tests moved to their plugins' repositories.
