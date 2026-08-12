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

**Stack:** Laravel 12 · PHP 8.4 · Filament 5 · Livewire 3 · Tailwind v4 · SQLite

---

## The rules that matter most

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

### 3. Verify, don't assume

Every claim in a summary must be backed by something that ran. Bugs found only
because of this: a 56%-confidence OCR page rendering upside-down text; hyphen
rejoining producing `port hole`; a stale `MediaBrowser` scoping to the previous
profile; two admin links where only one was gated.

- Run the code path, don't reason about it.
- Test edge cases explicitly (empty, single item, boundary, malformed).
- When a fix is claimed, show the before and after.
- If something is unverified, say so plainly.

### 4. Follow the existing idiom

Match the surrounding comment density, naming and structure. Comments explain
**why**, never what — especially where a non-obvious decision was made. If a
choice looks strange, it usually prevented a specific bug; say which.

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
resume points, highlights and watchlist. **Profiles are not a security
boundary** — no password, switching is a convenience, exactly as commercial
services treat it. Permissions live on `User`; the admin panel enforces its own.

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

### Two traps that have bitten repeatedly

**Inline `<style>` beats Tailwind.** Blade `<style>` blocks are emitted *after*
the compiled stylesheet, so `.panel { display: flex }` overrides `.hidden` at
equal specificity — the panel can never close. Re-state `.panel.hidden {
display: none }` at the **end** of the block, after every rule it must beat.

**Tailwind utility names are not CSS properties.** `ring-offset-color` in a raw
`<style>` block is silently dropped. Write real CSS there.

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

### While building

1. **Small, verifiable steps.** Build, run it, then continue.
2. **Lint as you go** — `php -l` on PHP, `node --check` on JS.
3. **Test the actual path**, including failure modes and edge cases.
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
