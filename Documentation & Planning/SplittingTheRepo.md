# Splitting the repository

A plan, not a change. Nothing moves until this is agreed.

## What prompted it

"Mac's setup broke on Windows even when bundling for Windows." That is a real
complaint and the bundling genuinely differs — Unix runs Caddy in front of
php-fpm, Windows runs FrankenPHP as a single binary because **PHP ships no
php-fpm SAPI on Windows at all**. Not a configuration difference; two
topologies.

The question is whether splitting repositories fixes it.

## What is actually in the tree

Tracked files, by top-level directory:

| | files | what it is |
|---|---|---|
| `app` | 350 | Laravel |
| `tests` | 225 | Laravel |
| `changelog` | 207 | both |
| `resources` | 148 | Laravel |
| **`src-tauri`** | **78** | **the desktop shell** |
| `database` | 77 | Laravel |
| `public` | 57 | Laravel |
| `config` | 27 | Laravel |
| **`server`** | **19** | **runtime templates + supervisor units** |
| `scripts` | 8 | both |

So the desktop half is **97 files of about 1,250**. The rest is a Laravel
application that happens to live beside it.

## The dependency runs one way

`src-tauri` reaches into the Laravel tree — it runs `artisan`, it locates a
directory holding `artisan`, `public/` and `server/`.

The Laravel application contains **zero references to `src-tauri`**. Checked,
not assumed.

That asymmetry is what makes a split tractable: one side knows about the
other, and the knowledge is already funnelled through two mechanisms.

## The boundary already exists

Two pieces of existing design do most of the work.

**1. The app is packaged as an opaque artifact.** `tauri.server.conf.json`
declares `app-payload.zip` as a bundle resource. The shell does not reference
`app/` or `resources/`; it unpacks a zip. The contents of that zip are defined
by an explicit `$include` list in `scripts/package-app-payload.php` —
`artisan`, `composer.json`, `app`, `config`, `public`, `resources`, `routes`,
the migrations. **That list is already the boundary**, written down and
enforced.

**2. Resolution is already indirect.** `resolve_layout` finds the app at
runtime by looking, in order:

```
resource_dir/artisan exists?        -> the bundle carries it
SOUNDCHEX_APP_DIR set?              -> use that
a payload was provisioned?          -> data_dir/app
otherwise                           -> resource_dir
```

The `SOUNDCHEX_APP_DIR` override exists so a machine already serving a library
out of a checkout keeps doing so after an upgrade. That same override is what
makes a two-repo development loop work with no new code.

## The proposed split

Two repositories, not four.

**`soundchex-server`** — the Laravel application. Everything the browser, the
iOS app and every future client talks to. Ships as a release artifact: the
payload zip plus the per-platform PHP runtimes that `build-server.yml` already
produces.

**`soundchex-desktop`** — the Tauri shell for all three desktop platforms.
`src-tauri/`, `server/supervisor/`, `server/templates/`, and the packaging
scripts. Consumes a published server release rather than a sibling directory.

### Why not one repo per OS

The platform-specific code is small and already partitioned:

- `supervisor.rs`: **2** `cfg` branches in 337 lines
- `lib.rs`: **3** `cfg` branches
- `server/supervisor/` **already** splits `launchd` / `systemd` / `windows`
- `server/templates/` holds a Caddyfile and a php-fpm.conf — Unix-only,
  because Windows needs neither

Three repos would move those folders and duplicate every Tauri dependency,
every version bump and every shared fix around them.

And the failure mode runs the other way. The missing-window bug fixed in #259
broke macOS, Windows **and** Linux at once, because they share one `lib.rs` —
one fix covered all three. Split per-OS, it would have broken one platform and
gone unnoticed in the others until someone complained.

## What this does and does not fix

**It does not fix the Windows breakage on its own.** The cause of that was not
the repository layout: **nothing built the Windows Server app in CI**.
`build-server.yml` built the PHP runtime and never the app; the only thing that
had ever produced it was `reinstall-server-app.sh`, which exits unless
`uname -s` is `Darwin`. Mac-shaped assumptions could land with no Windows build
to catch them.

That is now fixed — the `app` job builds on macOS, Windows and Linux. **Keep
that green through at least one release before splitting.** It is the thing
that would catch a split going wrong on a platform that cannot be tested here.

**What the split does buy:**

- A Rust change and a Blade change stop landing in the same history
- The server can release on its own cadence; the desktop shell pins a version
- Clearer ownership when more than one person is working
- `changelog/` stops mixing "the scrubber announces its position" with
  "FrankenPHP ships no php-fpm"

## How it would be done

Each step leaves a working tree. Nothing is deleted until the replacement is
proven.

**Step 1 — prove the seam without moving anything.**
Build the desktop app against a server checkout at a *different path*, using
`SOUNDCHEX_APP_DIR`. If that works on all three platforms, the seam is real. If
it does not, stop: the split would not have worked either.

**Step 2 — publish the server as a consumable artifact.**
`soundchex-server-<version>.zip` (the existing payload) as a release asset.
The desktop build downloads a pinned version instead of running
`package-app-payload.php` against a sibling directory. Still one repo; only
the acquisition changes.

**Step 3 — extract the desktop repo.**
`git subtree split` over `src-tauri/`, `server/supervisor/`,
`server/templates/` and the packaging scripts, so history comes with it. The
old paths stay in place and keep building until step 5.

**Step 4 — move the workflows.**
`build-client.yml` and the `app` job follow the desktop repo.
`build-server.yml` stays. Both must be green on all three platforms before
anything is removed.

**Step 5 — delete the duplicated paths** from the server repo, and only then.

### Release process afterwards

Today one tag builds everything. Afterwards:

- `soundchex-server` tags `server-vX.Y.Z` and publishes the payload plus the
  four platform runtimes
- `soundchex-desktop` pins a server version in a manifest, tags
  `client-vX.Y.Z`, and builds the three desktop bundles against it
- A desktop release therefore names the server version it carries, which it
  does not do today

`cut-release` (the skill in `.claude/skills/`) is rewritten once, at step 4.

### What gets harder

Honestly, two things.

**A change spanning both repos becomes two PRs.** Adding a Tauri command with
a Laravel endpoint behind it is one commit today. Afterwards it is a server
release, a version bump, and a desktop PR.

**Bisecting across the seam gets worse.** "This broke somewhere in the last
week" is one `git bisect` today; afterwards it is two, with a version mapping
between them.

Both are the normal cost of a split. They are worth naming because the current
single repo is genuinely convenient, and that convenience is what is being
traded away.

## Recommendation

Do steps 1 and 2 now — they are useful on their own and reversible. `SOUNDCHEX_APP_DIR` already exists, so step 1 is a verification rather than a
change.

Hold steps 3–5 until the three-platform CI has survived a release. The split
is not what fixes Windows; the CI job is. Splitting first would mean
restructuring while the thing that proves the restructure worked is still
unproven.
