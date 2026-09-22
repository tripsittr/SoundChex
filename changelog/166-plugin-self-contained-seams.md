# 166 — Plugins can own a whole vertical: routes, migrations, bindings

The plugin platform (S-264) let a plugin contribute metadata sources, events,
filters, cover sources, notification targets and admin pages — but not its own
**routes**, **database schema**, or **service bindings**. So a feature-sized
plugin still needed core app code behind it. These three seams let a plugin be
fully self-contained: it can ship its endpoints, its tables and its services, and
disabling it removes all of them (S-314).

## Added to the Registry

- **`routes(path, prefix?, middleware?)`** — a plugin registers its own routes
  file, loaded only while the plugin is enabled and wrapped in the prefix and
  middleware it asks for (so a plugin API can sit under `auth:sanctum` like the
  core API). Disabling the plugin makes its endpoints 404.
- **`migrations(path)`** — a plugin ships a migration directory, registered with
  Laravel's migrator so `migrate` runs it alongside the core migrations. The
  plugin owns its tables.
- **`binding(abstract, concrete, shared?)`** and **`singleton(abstract, concrete)`**
  — a plugin binds its own services through the registry (attributed to the
  plugin) rather than reaching into the container directly.

`PluginServiceProvider` loads the registered routes and migrations after the
enabled plugins' `register()` has run, so only enabled plugins contribute them.

## Testing

- `PluginSelfContainedSeamsTest` (4 pass): a plugin registers a routes file with
  prefix + middleware; migration directories are collected and de-duplicated; a
  singleton returns one instance and a plain binding a fresh one; a
  plugin-registered route is actually routable.
- The existing plugin suites (loader, bundled, activity-log, playlist-porter)
  still pass (29).

## Next

This is the prerequisite for moving the playlist-porter engine into its plugin
(S-315), so porting becomes a fully removable plugin rather than core code with a
plugin UI.
