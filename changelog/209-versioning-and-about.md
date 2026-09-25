# A version worth the name, and a source offer that means something

S-401 (the licence gap) and S-402 (versioning).

## The problem

The desktop app could not say what it was. `tauri.conf.json` sat at `0.1.0`,
`Cargo.toml` agreed with it, and the repository had no tags at all. Every page
offered "source code" as a bare link to `main`.

That is a licence problem, not only untidiness. AGPL §13 asks a network-hosted
build to offer its users **its own** corresponding source. Builds ship from
`main` between releases, so two servers can report the same version and be
running different code — a link to `main` is the project, not the thing serving
you.

The README stated the fix as though it existed: "the About page links this
repository at the running version". There was no About page. That claim is now
true rather than softened.

## Named releases, two vocabularies

SoundChex names every minor. The phone uses **music** terms — Overture,
Cadence, Reprise. The server and desktop now use **film** terms, because that
half serves films as well as music, and because a name should say which app it
belongs to without anyone asking.

- `0.1` **Screening** — everything before versioning was real.
- `0.2` **Rough Cut** — this.
- `1.0` **Feature** — reserved, the way the phone reserves `Encore`.

`App\Support\AppRelease` mirrors iOS's `AppRelease.swift`. The full vocabulary
and the name bank are in `docs/Versioning.md`.

## Stamping

`scripts/stamp-release.mjs`, run by `npm run build`, takes the version from
`package.json` and writes it to `tauri.conf.json` and `Cargo.toml`, then writes
`APP_VERSION`, `APP_COMMIT` and `APP_SOURCE_MODIFIED` into `.env`.

The commit is the part that matters. The version alone cannot identify a tree;
version plus commit names exactly one.

A build made from a dirty working tree is marked modified, because nobody can
reproduce it from a public commit — and the About page says so rather than
linking a tree that is not what is running.

## Releasing

```bash
npm run release -- minor
git push && git push --tags
```

It refuses three things, each of which produces a release nobody can trace back
to source: a dirty tree, an already-used tag, and **a minor with no name in
`AppRelease::NAMES`**. That last one is the phone's old bug — nine minors whose
names lived only in the changelog, so Settings showed a bare number while the
release notes called them something else (S-380).

## The About page

`/app/about`, linked from the account menu on every page. It states the version
and name, the commit, and whether the build was modified, and links the source
**pinned to that commit**.

Where there is no commit it says so plainly instead of quietly linking the
repository and letting the reader assume. A build that cannot honour §13 on its
own should admit it — the person reading is the one who needs to know.

The pre-auth footer links the pinned source directly, since About is behind
auth and §13 covers people who have not signed in.

An operator running a modified build sets `SOUNDCHEX_REPO_URL` to their own
repository. Their users are owed their source, not this project's — which is
why the URL is a setting and not a constant, and why there is a test for it.

## Testing

10 new tests, 1,100 passing. They cover the film names, the pinned URL, the
operator's own fork, and the three things the About page has to say: the
version, the modified warning, and the admission when there is no commit.

## Known gaps

- The Tauri updater compares versions but nothing publishes releases yet, so
  `npm run release` tags and CI builds, and distribution is still manual.
- The phone and desktop version maps are separate files that must not drift.
  Nothing enforces that beyond both documents pointing at each other.
