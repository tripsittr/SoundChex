---
name: cut-release
description: Cut a SoundChex release — desktop client, bundled server, or iOS. Use when the user asks to publish, release, ship a version, or tag a build. Covers versioning, the per-platform artifacts, what is signed and what is not, and the checks that must pass before a tag is pushed.
---

# Cutting a release

A tag here is public and hard to retract: it builds installers, publishes them,
and (for a stable tag) points every installed copy's updater at the result. So
the order is **check, then tag** — never the reverse.

## Before anything, know which release this is

Three independent release trains, with separate tags and separate versions.
Never assume the user means all three.

| Train | Tag | Builds | Artifacts |
| --- | --- | --- | --- |
| Desktop client | `client-v*` | macOS, Windows, Linux | `.dmg`, `.msi`, `.AppImage`/`.deb` |
| Bundled server | `server-v*` | macOS, Linux, Windows | `.tar.gz`, `.zip`, `.sha256` |
| iOS | `v*` + TestFlight | iOS | uploaded from Xcode, see `SoundChexiOS/Plans/TestFlight.md` |

A tag with a suffix — `client-v0.2.0-beta.1` — publishes a **pre-release**.
GitHub keeps those off "Latest", which matters: the updater endpoint follows
`releases/latest`, so a pre-release cannot push itself to someone running the
stable build.

## Versioning

Desktop and server versions come from `docs/Versioning.md`: SemVer with a
**film-themed name per minor** (0.2 "Rough Cut"). iOS uses **music** names —
see `SoundChexiOS/Plans/Versioning.md`. Do not mix the vocabularies.

The name must exist in `App\Support\AppRelease::NAMES` (desktop) or
`AppRelease.swift` (iOS). **A name written only in a changelog is not
shipped** — `npm run release` refuses an unnamed minor for this reason.

One version source per train:

- Client: `package.json`, then `node scripts/stamp-release.mjs` writes
  `tauri.conf.json` and `Cargo.toml`. Never edit those two by hand.
- Server bundle: versioned by the tag.

## Pre-flight checks

Run these and report the results. Do not tag while any is failing.

```bash
# 1. Clean tree on main, in sync with the remote.
git status --porcelain && git log --oneline -1 origin/main

# 2. The suites.
php artisan test          # 1100+
npx vitest run            # JS unit
cargo check --manifest-path src-tauri/Cargo.toml

# 3. The stamp matches what is about to be tagged.
node scripts/stamp-release.mjs

# 4. The tag is unused.
git tag --list | grep <tag>
```

**Check the updater endpoint resolves.** It is baked into the binary and
cannot be changed after release:

```bash
python3 -c "import json;print(json.load(open('src-tauri/tauri.conf.json'))['plugins']['updater']['endpoints'][0])"
```

It must be the GitHub releases URL. It previously named a Tailscale machine
that no longer existed, which would have shipped a dead updater to every
install (S-421).

## What is true per platform — state this honestly in the notes

| Target | Signed | Run on real hardware |
| --- | --- | --- |
| Client macOS | **no** | yes, daily |
| Client Windows | **no** | **never** |
| Client Linux | **no** | **never** |
| Server macOS / Linux | n/a | yes |
| Server Windows | n/a | **never** |

Unsigned has visible consequences, and the release notes should say so:

- **macOS**: Gatekeeper reports the app is *damaged*, not merely unidentified.
  Users need right-click → Open, or `xattr -dr com.apple.quarantine`.
- **Windows**: SmartScreen shows "Windows protected your PC" → More info →
  Run anyway.

Never describe an untested platform as supported. "Builds in CI" is not
"works".

## Cutting it

```bash
# Client
npm run release -- minor        # or patch, or an explicit x.y.z
git push && git push --tags

# Server
git tag -a server-v0.2.0 -m "..." && git push origin server-v0.2.0
```

`npm run release` refuses a dirty tree, a used tag, and an unnamed minor.

Then **watch the build** — a tag that fails to build leaves a tag with no
release behind it:

```bash
gh run watch $(gh run list --limit 1 --json databaseId -q '.[0].databaseId')
```

## Afterwards

1. **Verify the artifacts are attached** to the release, not just built.
2. **Update the download page** in the Website repo:
   `resources/views/download.blade.php` — each platform row has a `ready`
   flag. A beta gets `ready => true` *and* a Beta badge saying it has not been
   tested on that OS.
3. **Move the tracker item** to done with the tag in the note.
4. **Write the changelog entry** if the PR did not.

## Things that have actually gone wrong

- **A dead updater endpoint** baked into every binary (S-421).
- **A 222 MB artifact that cannot be downloaded** to check — the packaging
  script now prints its own manifest and fails on a missing binary (S-420).
- **A download URL that 404s**, shipping an HTML error page as a "binary".
  Always fetch a release asset once before trusting its URL.
- **`gh run download` exits 0 on failure.** Check the directory is non-empty,
  not the exit code.
- **Logs are not readable while a run is in progress**, so an empty grep is
  not evidence of absence.

## Never

- Tag before the checks pass.
- Describe an untested platform as tested.
- Edit `tauri.conf.json` or `Cargo.toml` versions by hand.
- Publish a stable tag for something only run in CI — use `-beta.N`.
