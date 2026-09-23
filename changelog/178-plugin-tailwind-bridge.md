# 178 — Plugins can write Tailwind again

**Merged** 2026-09-23 · **Issues** S-350

A plugin's Blade could not use Tailwind. The app's CSS is compiled before
release, and a plugin installed from the catalogue lives outside the repo on
the user's machine, so its markup never existed when that bundle was built —
utility classes written in a plugin simply had no rule, and nothing errored to
say so. Two rounds of UI fixes in Playlist Porter did nothing for this reason.

## What changed

### The bundled Tailwind CLI compiles a plugin's own stylesheet

`StyleCompiler` runs Tailwind's standalone binary over one plugin's views when
that plugin is **enabled**, writing `dist/plugin.css` inside the plugin. The
binary sits beside the PHP binary in the bundled runtime — the convention
`config/transcode.php` already uses for ffmpeg — so `TAILWIND_PATH`, then the
bundle, then `PATH`.

It is fast because it is scanning one plugin rather than an application:
**11 KB in well under a tenth of a second** for Playlist Porter.

### A plugin can use the product's palette

The generated stylesheet imports `resources/css/tokens.css` and maps the
tokens into Tailwind's `@theme`, so a plugin writes `bg-sc-base-900` or
`text-sc-accent` and gets the real colour. Only the surfaces, text and accent
are exposed: a plugin reaching for the whole internal palette is how a UI ends
up looking like a different product.

A plugin that needs more control ships its own `resources/css/plugin.css` —
its own `@theme`, extra `@source` paths, plain rules — and that is compiled
instead.

### Serving it

`/plugin-styles/{plugin}.css` hands out the compiled file for an **enabled**
plugin to a signed-in user, with the correct `text/css` type (as `text/plain`
a browser silently refuses to apply it). The admin panel links each one after
its own theme, cache-busted on the file's mtime.

## Worth knowing

- **Compilation is best-effort.** No CLI, no views, or a failed build leaves
  the plugin with whatever CSS it ships — exactly the behaviour that existed
  before. Enabling a plugin never fails over styling.
- `php artisan plugins:styles` rebuilds everything, for an app upgrade (the
  tokens may have moved), a plugin updated in place, or plugins enabled before
  this existed.
- Arguments are passed to the process as an array, never a shell string: the
  paths include a plugin-controlled directory name.
- **Licensing** is recorded in `LicenseAudit.md` and
  `server/THIRD-PARTY-LICENSES.txt` (S-351): Tailwind is MIT, and the binary
  embeds Bun (MIT) and JavaScriptCore (LGPL-2.1-or-later).

### Shipped with the server runtime

`package-runtime.sh` fetches the CLI for every platform it packages — macOS
(arm64/x64), Linux (arm64/x64) and Windows (x64) — verifying it against
upstream's published `sha256sums.txt` before it is staged, and refusing to
package on a mismatch. It lands beside `php`, which is how
`config/plugin-styles.php` finds it, so a packaged server compiles plugin
styles with no configuration at all. `SKIP_TAILWIND=1` builds a smaller
runtime; plugins then keep whatever CSS they ship.

Adds roughly 80 MB on macOS and 105 MB elsewhere, against the ~300 MB the
runtime already carries.

## Worth knowing (bundling)

The **client** app deliberately does not ship it. The client is a viewer that
connects to a server — it has no PHP runtime, runs no plugins, and could not
use the binary if it had one; `build-client.yml` says as much about the server
runtime generally. Bundling it there would add 80–105 MB of unreachable code
to every desktop client.

## Still wrong

- Compilation happens on enable, not on plugin update: a plugin updated in
  place keeps its old stylesheet until `plugins:styles` is run.
