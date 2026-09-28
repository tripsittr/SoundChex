# The plugin styling seam, and why it was doing nothing

S-349.

## What was meant to happen

A plugin's Blade cannot use the app's Tailwind classes: the app's CSS is
compiled before release, and a catalogue-installed plugin lives on the user's
machine, so its markup never existed when that bundle was built. The seam for
this shipped under S-350 — the bundled Tailwind CLI compiles the plugin's own
stylesheet, served from `dist/plugin.css`.

## What was actually happening

Nothing. `config/plugin-styles.php` fell back to a bare `tailwindcss` on
PATH, with a comment describing that as "how a development machine without the
bundle still works". Tailwind v4 ships no global binary — it is an npm package
— so on a development checkout the name resolved to nothing and every plugin
skipped compilation.

The failure is silent **by design**: a missing CLI is not an error, because a
plugin should keep whatever CSS it ships. So the only symptom was CSS that
never changed.

Evidence it had never worked: the one compiled stylesheet on this machine was
11 KB dated 23 September and contained **zero** of playlist-porter's own
`scpp-*` classes. It was Tailwind utilities from some other build. The
plugin's real styling was coming entirely from hand-written `@once` blocks —
the exact thing the seam was built to remove.

Now `@tailwindcss/cli` is a dev dependency, the config looks in
`node_modules/.bin`, and a test asserts the configured binary resolves to a
real file. That test fails with a pointed message when it does not, which is
what should have happened in the first place.

## The other half of the seam

The issue proposed two things: let a plugin ship compiled CSS, *and* expose
the app's design tokens so it can match the theme. Only the first was built.

The tokens existed — `--sc-base-*`, `--sc-ink-*`, `--sc-accent` in
`resources/css/tokens.css` — and are already in scope on any admin page,
since the panel's theme imports them. They were simply never documented, so
plugin authors had no way to know, and hard-coded hex values instead.

`docs/plugins/README.md` now documents the full token table, with two rules:
never hard-code the accent (it is a user setting), and check contrast if you
put text on it.

## playlist-porter migrated

Both `@once` blocks are gone, replaced by `resources/css/plugin.css`. Its
tooltip hover was a hard-coded blue — the one thing on that page that ignored
the user's chosen accent. It uses `--sc-accent` now.

One trap worth recording: **the host's tokens are dark-mode values**. Applying
them unconditionally would have made the admin panel's light mode unreadable,
so the light values stay literal and the tokens apply under `.dark`. That is
the same split the hand-written CSS made; the dark half now follows the app
rather than approximating it.

Compiles in 95 ms to 2.5 KB.
