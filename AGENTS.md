# SoundChex — Working Agreement

Instructions for any agent or contributor working on this codebase. `CLAUDE.md`
points here; this file is the source of truth.

---

## What this is

A self-hosted media catalog for one household: music, films, TV and books in a
single library, enriched from free public APIs. It runs on the user's own
machine and is reached remotely over Cloudflare Tunnel or Tailscale.

Two surfaces:

| Surface | Path | Stack |
|---|---|---|
| Media center | `/app` | Blade + Alpine + Tailwind v4 (not Filament) |
| Admin panel | `/admin` | Filament 5 |

**Stack:** Laravel 13 · PHP 8.4 · Filament 5 · Livewire 4 · Tailwind v4 · SQLite

---

## The rules that matter most

### 0. Two machines work this repository

`a5` (Windows) and `macbookair` (macOS), each with an agent. **Mac writes every
fix; `a5` reports logs, findings and errors on the issue.** `a5` claims, Mac
fixes and opens a PR, **`a5` reviews and approves before Mac merges**, then both
test for real and Mac closes.

"For real" excludes PHPUnit, Pest, Vitest and Playwright — those still have to
pass, but they close nothing. Drive the actual app. The Cancel button shipped
with nine passing tests behind it and did nothing when clicked.

Sign every comment and issue `— Mac` or `— A5`. The machines cannot do the same
things, so an unsigned result is ambiguous.

`a5` has no Playwright, so its approval never means "works in a browser". An
approval names what it covered and what it could not; Mac tests browser-shaped
risk here before merging. Equally, `a5` is the *receiving* machine in a
transfer, so transfer behaviour cannot be verified from this side alone.

**Neither machine can measure the other's progress — ask, do not infer.** Byte
counters read a bursty copy as stalled. Worse, a number you changed yourself is
not a measurement: a hand-reset queue reads as a fix working. Reviewing also
perturbs the run, so **review in a worktree** while anything is copying —
checking a branch out swaps the code under a live worker. Copy `vendor` in
rather than symlinking it, or Composer resolves `App\` back to the main
checkout and the suite silently tests the wrong code.

Full agreement: [docs/WorkingWithTwoAgents.md](docs/WorkingWithTwoAgents.md).

### 1. Never commit media

The library is the user's own films, music and books. A single misplaced folder
pushed to a remote is expensive to undo and impossible to fully retract.

`.gitignore` is deliberately redundant about this — nested Laravel rules *plus*
explicit path and extension rules. Before any commit that touches `.gitignore`
or adds a storage path, verify:

```bash
git ls-files | grep -icE "\.(mp3|mkv|mp4|epub|pdf|flac|srt|vtt|sqlite)$"   # must be 0
git check-ignore -q "storage/app/private/media/library/x.mp3" && echo ok
```

The SQLite database is the whole catalog. It is ignored too.

### 2. This code moves and deletes the user's files

`LibraryOrganizer`, `DuplicateDetector` and the OCR/transcode pipelines all act
on real, often irreplaceable files. Rules learned the hard way:

- **Verify before destroying.** `DuplicateDetector::merge()` re-hashes both
  files immediately before deleting one, because a hash recorded days ago may
  be stale. Follow this pattern anywhere a delete is involved.
- **A confidence gate guards renames.** Only `MatchConfidence::Exact` may move
  a file. A wrong metadata match doesn't just mislabel a row — it refiles a
  book under the wrong author. Music is exempt because its tags are embedded in
  the file and authoritative about it.
- **Distinguish "nothing to do" from "failed."** Returning `null` for both made
  the organizer report 85 successes and one silent failure. Blank OCR pages
  were reported as errors and retried forever. Name each outcome.
- **Default to doing nothing.** Duplicate handling defaults to `review`, never
  auto-delete.
- **A stored hash describes a path. Clear it whenever the path moves.**
  `content_hash` is the bytes at `file_path`; rewriting one without the other
  leaves a fingerprint for a file the row no longer points at. That is not
  theoretical — after the S-328 path repair, **54% of hashes no longer matched
  their file**, byte-identical duplicate detection went blind, and a reviewed
  track kept returning to the queue because two rows on one file held two
  different hashes and neither matched the file (S-346). `LibraryOrganizer` and
  `library:reconcile-paths` now null the hash on every path write; anything new
  that moves a file must do the same.

### 3. Verify, don't assume

Every claim in a summary must be backed by something that ran. Bugs found only
because of this: a 56%-confidence OCR page rendering upside-down text; hyphen
rejoining producing `port hole`; a stale `MediaBrowser` scoping to the previous
profile; two admin links where only one was gated.

- Run the code path, don't reason about it.
- Test edge cases explicitly (empty, single item, boundary, malformed).
- When a fix is claimed, show the before and after.
- If something is unverified, say so plainly.
- **`grep` lies about generated output.** Minified CSS and JS are one long
  line, which `grep` may treat as binary and report nothing for — and a
  too-literal pattern finds nothing in *any* file. Both happened here: a
  "missing" Tailwind class was present all along, and a checksum match against
  upstream's `./name` prefix silently found nothing and would have skipped
  verification entirely. Count occurrences in Python, or use `grep -a`, before
  concluding something is absent.

### 4. Follow the existing idiom

Match the surrounding comment density, naming and structure. Comments explain
**why**, never what — especially where a non-obvious decision was made. If a
choice looks strange, it usually prevented a specific bug; say which.

### 5. Everything gets an issue

Tracking now lives in the **admin panel on the landing site** (the
`SoundChexWebsite` repo — Filament, `/admin` → **Tracker** / **Board**), backed
by an `items` table, **not** in `Documentation & Planning/Issues.md` (which is
now a pointer; the historical S-NN entries were imported into the tracker). An
"issue" here is anything we decide to build or change — a feature, a fix, a bug,
a piece of cleanup. A feature request is tracked exactly as a defect is.

- **Log the item before the work starts.** A sentence is enough. Add it in the
  panel, or from the website repo with `php artisan track:issue` (interactive, or
  with flags: `--platform --type --status --repo --ref --publish`).
- **Set `platform` and `repo`** so it lands in the right place (this server/
  desktop work is `platform=server-desktop`, `repo=SoundChex`).
- **Advance the item's `status`** (Planned → In progress → Shipped/Done, or
  Deferred) as the work moves — on the Board drag the card between columns, or
  from the website repo `php artisan track:move <id…> --to=<status>` (takes
  several ids at once; `--note` appends a dated line). "Shipped" is user-facing
  complete (on the public roadmap); "Done" is an internal closed ticket.
- **Promote to the public roadmap** by flipping `published` on the item — the
  same record appears on the site's `/roadmap`.
- **Historical id references** (`S-nn`) are preserved as the item's `ref`; keep
  citing them in commits/changelogs.
- **Verify a status against the code before trusting it.** Two entries were once
  wrong within a day of being written: one said `/login` had no rate limiting
  when it has always had it, another said the update check was broken when the
  device reports showed it working. Both were written from memory rather than
  checked.

### 6. Everything reaches main through a pull request

No exceptions, and no pushing to `main` directly. The PR is where a hundred
commits become one readable account of what changed, and merging locally
throws that away.

Opening one:

1. **Run what the change can break.** PHP and Vitest are 18 seconds together
   and always worth running. Playwright is 13 minutes on one worker — it is
   constrained that way because the browser tests share one SQLite database and
   one seeded library — so run it when the change can reach a browser:

   | Changed | Run |
   | --- | --- |
   | Docs, changelog, planning | PHP · Vitest |
   | PHP only — services, jobs, controllers, models | PHP · Vitest |
   | Blade, CSS, `resources/js`, `public/sw.js` | **all three** |
   | Migrations, or anything touching stored data | **all three** |
   | Not sure | **all three** |

   Targeted runs are cheap and worth preferring: `npx playwright test
   tests/e2e/downloads-batch.spec.js --project=mobile` is under a minute.

   A PR opened on an unverified branch is a PR whose description cannot be
   trusted — but "verified" means the suites that could have caught something,
   not all of them by reflex.
2. **Write the changelog** — `changelog/NNN-short-name.md`, numbered for the
   PR. Written when the PR is opened rather than after it merges, while the
   reasoning is still to hand.
3. **The PR body and the changelog say the same thing.** If they differ, one of
   them is wrong.
4. **Say what is still broken.** A PR that lists only wins is one nobody
   believes twice. If tests fail, say which and why; if something is unverified,
   say so.
5. **Publish anything you learned.** If the change taught you something another
   developer or agent would want — a convention, a trap, something that went
   wrong once — write it into `.claude/memory/` and ship it in the same PR.

   ```bash
   cp ~/.claude/projects/"$(pwd | tr '/' '-')"/memory/*.md .claude/memory/
   ```

   That folder is a *copy* of a local directory, so it goes stale silently
   unless refreshed as part of the change that made it stale. Check the
   published notes against the local ones and correct rather than ship: three
   were wrong on first publish, describing a plan convention replaced that
   morning.

### 7. Log anything that can fail

The app runs on a phone that is not in the room, so a failure nobody wrote down
is a failure reported as "it didn't work".

A `catch` that only shows a toast says something broke and not what. Record
what was attempted, what came back, and the id of whatever was being acted on.

- Client: `log()` / `logFailure()` / `loggedFetch()` from `resources/js/log.js`,
  which reaches the device report. A kind ending `:failed`, `:error` or
  `:timeout` is sent to the server unprompted.
- Server: `Log::warning` or `Log::error` with context. Every path that moves or
  deletes a file logs when it does not.
- **Not everything.** Storage denied under private browsing, an expected
  offline — these are legitimately silent, and making them noisy buries the
  lines that matter.

---

## Architecture

### Data model

`MediaItem` is the spine. Type-specific metadata hangs off it
(`MusicMetadata`, `MovieMetadata`, `ShowMetadata`, `BookMetadata`) and is
reached generically through `$item->metadata()`.

Attached records: `MediaTag`, `Person`, `Subtitle`, `PageText`, `BookAsset`,
`BookChapter`, `Annotation`, `MetadataVersion`, `MediaPlay`, `ReadingProgress`.

### Profiles

A household shares one account but not one taste. `Profile` carries history,
resume points, highlights, watchlist — **and capability**.

Permissions live on the profile, not the account. The household shares one
login, so an account-level permission would give everyone identical rights. The
first profile on an account is the owner: it short-circuits every check, so a
household can never end up with nobody able to administer it. Every other
profile starts with nothing and is granted what the owner chooses.

Because switching profiles is otherwise one click, **a profile holding elevated
permissions must have a PIN** — without it a member could pick the owner's
profile from a menu and inherit its rights, and the permission would be a label
rather than a boundary. PINs are hashed and attempts are throttled.

`Profile::can()` is the only place capability is decided. Two subjects exist
(account and profile) and a check that reads the wrong one fails open.

Resolve the viewer through `CurrentProfile`, never `Auth::id()` directly, so
per-person scoping stays in one place. Rows written before profiles existed
have a null `profile_id` and fall back to the account — don't break that.

`ContentGate` applies a profile's rating cap. Apply it in **every** browse and
search query and on detail/stream/watch routes: a cap enforced in some places
and not others reads as working while failing.

### Metadata pipeline

`MetadataSource` implementations run in priority order via `MetadataPipeline`,
registered in `config/metadata_sources.php`. Missing classes are skipped with
`class_exists()`, so a source can be listed before it's written.

- API keys come from `SettingsService`, **never** `env()` or `config()` in a
  source, and are stored encrypted in the `settings` table.
- `source = 'manual'` on a tag or field must never be overwritten.
- A snapshot is captured before every enrichment run (`MetadataHistory`), so a
  provider revising its own record is always recoverable.

### External binaries

Optional and feature-degrading, never fatal. Always check availability and give
an actionable message naming the install command.

| Binary | Used for |
|---|---|
| `ffmpeg` / `ffprobe` | transcoding, subtitle extraction |
| `tesseract` | OCR of scanned pages |
| `pdftoppm` / `pdftotext` / `pdfinfo` / `pdfimages` / `pdftohtml` | book text, images, outline |

---

## Frontend

Tailwind **v4** — `@import 'tailwindcss'` with `@theme` tokens. There is no
`tailwind.config.js` and no `postcss.config.js`.

Entrypoints: `media-center`, `reader`, `watch`, `player`, `now-playing`.
Heavy libraries (pdf.js, epub.js, JSZip) are dynamically imported so a book
reader doesn't ship a PDF engine to someone playing music.

Run `npm run build` after any change to `resources/`.

### Three traps that have bitten repeatedly

**Inline `<style>` beats Tailwind.** Blade `<style>` blocks are emitted *after*
the compiled stylesheet, so `.panel { display: flex }` overrides `.hidden` at
equal specificity — the panel can never close. Re-state `.panel.hidden {
display: none }` at the **end** of the block, after every rule it must beat.

**Tailwind utility names are not CSS properties.** `ring-offset-color` in a raw
`<style>` block is silently dropped. Write real CSS there.

**A plugin's Blade cannot use the app's Tailwind.** The stylesheets declare
their `@source` paths (`resources/css/app.css`, `resources/css/filament/admin/
theme.css`) and none covers plugins — and a plugin installed from the catalogue
lives in the user's application-support directory, outside the repo, so its
markup did not exist when `public/build` was compiled. A utility class written
in a plugin simply has no rule, and **nothing errors to say so**: two rounds of
UI fixes shipped doing nothing before this was understood (S-347).

Filament's own components are safe — `x-filament::button`, `badge`, `modal`,
`slide-over` — because those classes come from Filament's build. Anything else
a plugin needs must come from CSS the plugin ships itself, or from the Tailwind
CLI the runtime now bundles, which compiles the plugin's own stylesheet when it
is enabled (S-350, `app/Plugins/StyleCompiler.php`). A plugin can also map the
app's design tokens into `@theme` and write `bg-sc-base-900`, so plugin UI
matches the product rather than inventing its own palette.

---

## Production workflow

### Before starting

1. **Check `Documentation & Planning/` for the active plan.** One at a time —
   see [Plans](#plans). Never assume project state from this file or from
   memory; read the plans and the code.
2. Read the relevant existing code. This codebase has accumulated non-obvious
   decisions; most "obvious" improvements were already tried.
3. For anything touching more than ~3 files, write or update a plan document
   first, and say what you'll do.
4. If a requirement is ambiguous in a way that changes the work, ask. If it
   doesn't, pick the sensible default and say which.

### Testing

**Write tests for everything you build.** Not as a final pass — alongside the
work, so a guard you add today still holds a month from now. Every bug found in
this project so far was caught by hand; a test is the difference between
noticing a regression and shipping one.

Three layers, each for a different class of bug. Use the one that fits what you
wrote, and more than one when the work spans layers.

#### PHP feature and unit tests — `php artisan test`

The default. Required, not optional, for:

- **Anything that moves or deletes a file.** `LibraryOrganizer` and
  `DuplicateDetector` act on irreplaceable data. Test the refusals as hard as
  the successes: that a wrong-confidence match does *not* move, that a diverged
  file is *not* deleted, that an existing file is never overwritten.
- **Anything that decides access.** `ContentGate`, profile permissions, panel
  gates. Test by **direct URL**, not by whether a link renders — Filament and
  Laravel routes resolve whether or not anything links to them, so a test that
  only checks navigation proves nothing.
- **Parsers and converters.** `EpisodeParser`, `SubtitleConverter`, OCR
  parsing, text reflow. These have known-tricky inputs; cover both directions —
  what must parse *and* what must be refused. `Blade Runner 2049` is not season
  20 episode 49.
- **Anything with a fallback.** Test the fallback path, not just the happy one.
  A silent fallback that never runs correctly is invisible until it matters.

#### Vitest — `npm run test`

Pure JavaScript logic with no DOM: paragraph reflow, rectangle merging,
timestamp conversion, byte formatting, quota arithmetic. Fast, so there is no
excuse for leaving an algorithm uncovered.

#### Playwright — `npm run test:e2e`

Integration behaviour a unit test cannot see, and where most real bugs have
lived:

- Does audio survive a navigation?
- Does a highlight persist across a reload?
- Does a downloaded file play with the network off?
- Is a member profile actually refused in the admin panel?

Reach for this whenever the work spans a page load, a swapped DOM, or browser
storage.

Run `tests/e2e/bootstrap.sh` first. It builds an isolated app — its own SQLite
file, its own storage root (`LOCAL_DISK_ROOT`), and media generated by ffmpeg —
and refuses to run unless both paths are scratch paths, because it deletes them
before rebuilding.

Two traps that made browser tests lie here:

- **Assert the mechanism, not just the URL.** A full page reload satisfies a
  URL check while destroying the very thing under test. `navigateWithinApp()`
  sets a `window` marker and confirms it survived, so a reload cannot pass as
  an SPA swap.
- **Check the property name exists.** `window.soundchexPlayer.el`, not
  `.audio`. A typo inside `page.evaluate` yields `undefined`, the `?? 0`
  fallback kicks in, and the assertion reports "not playing" no matter what
  the code does.

### Audit the suite before calling a plan done

Passing tests are not evidence on their own. Before renaming a plan `DONE_`,
check for all three:

- **Toothless.** Break the guard on purpose and confirm a test goes red. If
  nothing fails, the test is decoration. This has been done for the organizer's
  confidence gate, the duplicate delete re-verification, the rating cap, profile
  permissions and SPA navigation — each caught by the tests written for it.
- **Redundant.** Several tests covering one branch through different wording
  inflate the count without widening coverage. Spend that effort on an
  uncovered refusal instead.
- **Gaps.** Ask what is *not* covered — the refusals, the degraded paths (no
  ffmpeg, storage throwing, corrupt stored JSON, an older stored blob missing
  newer keys), and the boundaries the design deliberately accepts. Pin the
  accepted limits too, so an intended boundary is distinguishable from a
  regression.

### Tests must never touch the real library

Non-negotiable. The database is `:memory:` and the filesystem is faked with
`Storage::fake()`. A test that moves a real file, or writes into
`storage/app/private`, is worse than no test — it can destroy the thing it was
written to protect. Never point a test at a real media path, and never rely on
a file already existing on the machine.

Network is faked too. TMDB, OpenSubtitles and Open Library are stubbed with
`Http::fake()`; a suite that fails when the wifi drops is not a suite.

### The check that matters

**Break the guard on purpose and confirm a test fails.** Remove the confidence
check, or the byte re-verification, and run the suite. If it still passes, the
test is decorative — it asserts the code ran, not that it protected anything.
Put the guard back afterwards.

### While building

1. **Small, verifiable steps.** Build, run it, then continue.
2. **Lint as you go** — `php -l` on PHP, `node --check` on JS.
3. **Write the tests with the code**, at the layer that fits it (see Testing
   above). Cover the refusals, not only the successes.
4. **Clean up test data.** Never leave probe rows, files or profiles behind.

### Before committing

```bash
php artisan optimize:clear
npm run build
php artisan test            # if tests cover the area
git status --porcelain      # review every file
```

Then verify no media is staged (see rule 1).

### Committing

Commit in **logical batches**, not one enormous change. A batch is one coherent
unit — a migration with its model and admin resource, not "everything from
Tuesday."

Message format:

```
Short imperative summary under 72 chars

Why this change was needed and what it does. Note any non-obvious
decision and the bug it prevents. Wrap at 72 columns.
```

**Never include AI artifacts.** No `Co-Authored-By` trailers, no "Generated
with" lines, no mention of AI, agents or LLMs in commit messages, PR titles, PR
bodies or any published content. Commits read as authored by the developer.

Commit or push **only when asked**. Branch first if on `main`.

### Reporting back

- Lead with what changed and what it means for the user.
- Name bugs found along the way, including ones you caused.
- State plainly what is **not** done, and what is unverified.
- No inflated claims. "Verified end to end" means it was.

---

## Plans

**`Documentation & Planning/` is the record of what is built, in progress, and
intended.** Do not track project state in this file — it drifts within days and
then quietly misleads. Read the plans instead.

### One plan at a time

Work on **exactly one plan until it is complete**. Not two in parallel, not a
little of the next one while waiting. A half-finished feature is worse than an
unstarted one: it looks done, gets built on, and its gaps surface later as
bugs. Kids mode filtered nothing for a while precisely because it was left
half-built while other work started.

If something urgent interrupts, finish or explicitly park the current plan —
say so plainly — before opening another.

### Naming

| Prefix | Meaning |
|---|---|
| *(none)* | Proposed or in progress |
| `DONE_` | Built, tested, and verified working |

Reference documents — `Status.md`, `UsersAndProfiles.md`, `RemoteAccess.md` —
describe how something works rather than proposing work, so they are never
prefixed. They are corrected when they drift, not completed.

Rename the file to `DONE_<name>.md` **only** when all three are true:

1. Every item in the plan is built.
2. It has been exercised against real data, not just linted.
3. Nothing in it is known-broken or knowingly deferred. Partial delivery keeps
   the original name, with the remaining work written down inside it.

A plan that turns out to be wrong gets corrected, not silently abandoned.

### Working a plan

1. Read it fully before starting. Check it against the code — plans drift too.
2. Note decisions and discovered constraints **in the plan** as you go, so the
   reasoning survives the session.
3. When complete: verify, rename to `DONE_`, and commit that rename with the
   work it describes.

Plans are versioned. They are the project's memory of why things are the way
they are.

---

## Setup

```bash
composer install
npm install
php artisan migrate
php artisan storage:link
npm run build

# Optional, feature-degrading if absent
brew install ffmpeg tesseract poppler        # macOS
apt install ffmpeg tesseract-ocr poppler-utils   # Debian
```

Locked out? `php artisan user:password [email]` resets an account from the
console and verifies the new credentials authenticate before reporting success.
