# 177 — The licence audit covers what we actually ship

**Merged** 2026-09-23 · **Issues** S-351

The audit described FFmpeg as something that *would* be bundled later. It is
bundled now, and it is the full GPL build. The audit and the notices have been
reconciled against the binaries that actually ship, and the Tailwind CSS CLI
added ahead of the plugin-stylesheet work (S-350).

## What changed

### The audit names every bundled binary

`LicenseAudit.md` gains a table of the binaries in `src-tauri/runtime/bin` —
PHP, Caddy, FFmpeg, the CA bundle, and the planned Tailwind CLI — with each
licence and why it is compatible. These are invisible to the Composer, npm and
Cargo scans, because no package manager knows about them.

The FFmpeg build was verified against the shipped binary rather than the plan:
`--enable-gpl --enable-version3`, and **no `--enable-nonfree`**. That last
point is the one that would actually break redistribution, GPL or not.

### The Tailwind CLI's obligations, written down before it ships

Tailwind CSS is MIT and already an npm dependency, so the standalone binary is
a new *form* of a project we already use rather than a new project. It embeds
Bun (MIT) and JavaScriptCore/WebKit (**LGPL-2.1-or-later**); the notice file
records both and where to obtain their source. The project already
redistributes LGPL-2.1 components (`libiconv`, `libmp3lame`) the same way.

## Worth knowing

- The website's credits page claimed to list "every third-party dependency we
  ship" and listed only package-manager dependencies. The bundled binaries are
  now a section there too (website repo, same issue).
- The two terms pages named PHP, Caddy and SQLite but not FFmpeg. Both now name
  it and the Tailwind CLI, and point at the notice file for source
  availability. Effective date moved to 23 September 2026; all 17 PDFs
  regenerated.

## Still wrong

- `resources/data/credits.json` is maintained by hand. The bundled-binary
  entries will drift from `src-tauri/runtime/bin` unless something generates
  them — worth a command that reads the runtime directory.
- "Draft pending legal review" still stands on every policy: an attorney
  reading the suite is a separate task no licence scan replaces.
