# A skill for cutting releases, and an updater that points somewhere real

S-421.

## The updater pointed at a machine that no longer exists

`tauri.conf.json` had the updater checking
`macbookair.tail7e590c.ts.net` — a Tailscale device name from an earlier
machine. It answers nothing; the current machine is `el-laptop`.

That is worse than a broken link, because the endpoint is **baked into the
binary at build time**. Every copy of a release would carry it, and fixing it
afterwards means everyone reinstalls by hand.

It now reads a `latest.json` from GitHub releases. Two reasons over pointing it
at a server:

- An endpoint on the maintainer's laptop only works while that laptop is awake
  and the user is on the same tailnet. Someone else's install would never see
  an update.
- `releases/latest` **skips pre-releases**, so a beta cannot offer itself to
  someone running the stable build.

First-time installers come from the website; this endpoint is upgrades only.

**It is correct but not yet functional.** The updater refuses an update whose
minisign signature is missing, and nothing generates one — noted in the config
rather than left to be discovered.

## The client workflow could not publish

`build-client.yml` built macOS, Windows and Linux installers and uploaded them
as artifacts, but had no release job — only the server workflow did. A
`client-v*` tag built and then stopped.

It now publishes on that tag, marking a pre-release automatically when the tag
carries a suffix. Installers are collected by extension rather than by path,
because Tauri writes each bundle into its own directory and the artifact
download flattens them.

The `paths:` filter needed a note too: it applies to branch pushes only, but it
reads as though a tag push carrying none of those paths would build nothing.

## The skill

`.claude/skills/cut-release/SKILL.md` — the three release trains and their
tags, which version file is authoritative for each, the pre-flight checks, and
a table of what is signed and what has actually been run on hardware.

It also records the failures this project has already had: the dead endpoint, a
222 MB artifact too large to download as a check, a release URL that 404s and
ships an HTML error page as a binary, `gh run download` exiting 0 on failure,
and logs being unreadable mid-run so an empty grep proves nothing.

The rule it exists to enforce: **never describe an untested platform as
tested.** Windows and Linux build in CI and have never been run.
