# 143 — Plugins live in a data directory outside the install

A downloaded server release was showing the plugins folder as an absolute path
baked from whatever machine built it, and keeping plugins under the app's own
tree. Both are wrong for something an operator installs and upgrades.

## Where plugins live

Plugins now resolve to an OS-conventional per-user **data directory outside the
install**, via `App\Support\DataPaths`:

- Linux/BSD — `$XDG_DATA_HOME/soundchex/plugins`, else `~/.local/share/soundchex/plugins`
- macOS — `~/Library/Application Support/SoundChex/plugins`
- Windows — `%LOCALAPPDATA%\SoundChex\plugins`

`SOUNDCHEX_PLUGINS_PATH` overrides the plugins directory; `SOUNDCHEX_DATA_DIR`
moves the whole data root. Resolution never throws — a service account with no
resolvable home falls back to `storage/app`, so the app always has a directory.

The directory is created on first scan. Installs from before this move (plugins
under `storage/app/plugins`) are migrated across once, on discover, without
overwriting anything already at the destination — so an upgrade doesn't orphan an
operator's plugins.

## Managing the folder is a home-server surface

Plugin files are the server's own disk, so the folder is managed from the machine
running SoundChex, not a remote browser:

- On the server itself (a loopback request), the Plugins page shows the folder
  path and an **Open Plugins Folder** button that reveals it in the host's file
  manager (`open` / `xdg-open` / `explorer`, best-effort — a headless box shows
  the path to navigate to instead). The reveal passes the path as a single
  process argument, never through a shell.
- From a remote browser, the path and button are withheld; the page says plugin
  folders are managed on the server, and remote admins still browse, install and
  enable from the catalogue.
- Behind a loopback reverse proxy every request looks local;
  `SOUNDCHEX_PLUGINS_LOCAL_MANAGEMENT=false` forces those controls off.

The admin page no longer prints `config('soundchex.plugins.path')` unconditionally
— that was the line leaking the build machine's absolute path to every install.

## Tests

- `PluginDataDirectoryTest` (5): default is outside the install; `SOUNDCHEX_PLUGINS_PATH`
  and `SOUNDCHEX_DATA_DIR` overrides; trailing separators trimmed.
- `PluginsPageTest` (+3): path and button shown on the server, withheld remotely,
  and forced off by the override.
- Full suite green (832).
