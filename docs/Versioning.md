# Versioning — server and desktop

SemVer, with a **name for every minor release**. The phone does the same thing
(`SoundChexiOS/Plans/Versioning.md`) — what differs is the vocabulary.

| App | Names | Examples |
| --- | --- | --- |
| **Phone** (iOS, iPadOS) | Music terms | Overture, Crescendo, Cadence, Reprise |
| **Server and desktop** (macOS, Windows, Linux) | **Film terms** | Screening, Rough Cut |

Two vocabularies because the desktop half serves films as well as music, and
because a name should tell you which app it belongs to without your having to
ask. "Reprise" is always the phone; "Rough Cut" is always the desktop.

`Feature` is held back for **1.0.0** the way the phone holds `Encore`. Don't
spend it early.

## The rules

- **MAJOR** (`0.x → 1.0`) — the deliberate break. New name.
- **MINOR** (`0.2.x → 0.3.0`) — new features. **New name**, the next unused one.
- **PATCH** (`0.2.0 → 0.2.1`) — fixes. **Same name.** All of `0.2.x` is "Rough
  Cut"; the name is keyed on `MAJOR.MINOR` alone.

## The name must be in the code

`App\Support\AppRelease::NAMES` is what the app reads. **A name written only in
a changelog is not shipped** — the phone learned this the hard way and spent
nine minors showing a bare number in Settings while the release notes called
those releases something else (S-380).

`npm run release` refuses to tag a minor with no entry in that map, which is
the check that stops it happening again.

## Cutting a release

```bash
npm run release -- minor      # 0.2.0 → 0.3.0
npm run release -- patch      # 0.2.0 → 0.2.1
npm run release -- 0.4.0      # explicit
git push && git push --tags
```

It bumps `package.json`, stamps `tauri.conf.json` and `Cargo.toml` to match,
commits and tags. It refuses on a dirty tree, an already-used tag, or an
unnamed minor.

## Why any of this matters

Until S-401 the desktop app had no version worth the name: `tauri.conf.json`
sat at `0.1.0` and the repository had no tags at all. That is a licence
problem, not only untidiness.

AGPL §13 asks a network-hosted build to offer its users **its own**
corresponding source. A link to `main` is not that — builds ship from `main`
between releases, so two servers can report the same version and run different
code. So every build is stamped with both its version and the **commit** it was
built from (`scripts/stamp-release.mjs`, run by `npm run build`), and the About
page links the source pinned to that commit.

A build made from a dirty tree is marked modified, and says so, because nobody
can reproduce it from a public commit — and an operator running modified code
owes their users *their* source, which is why `SOUNDCHEX_REPO_URL` exists.

## The names

| Version | Name | What it was |
| --- | --- | --- |
| 0.1 | Screening | Everything before versioning was real — the 0.1.0 placeholder era. |
| 0.2 | Rough Cut | Real versions, stamped builds, and an About page that can honour §13. |
| 1.0 | Feature | Reserved. |

## The name bank

Film terminology, roughly in the order a film is made.

**Shooting** — Take, Reel, Dailies, Rushes, Set Piece, Establishing, Close Up,
Wide Shot, Tracking, Dolly, Crane, Steadicam, Insert, Cutaway, Two Shot.

**Cutting** — Rough Cut, Fine Cut, Final Cut, Splice, Montage, Dissolve, Fade,
Jump Cut, Match Cut, Cross Cut, Reprise (taken — phone), Assembly, Trim,
Continuity.

**Sound and finish** — Foley, Dub, Mix, Score, Colour, Grade, Master, Print.

**Showing** — Screening, Premiere, Matinee, Double Feature, Marquee, Trailer,
Reel One, Opening Night, Wide Release, Director's Cut, Feature (reserved for
1.0).
