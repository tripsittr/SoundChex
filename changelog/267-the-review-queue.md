# 267 — The review queue

**Merged** 2026-10-06 · **Issues** #480, #481, #482

The review screen was *"confusing, hard to navigate and read, and the table is
not user friendly"*. It is now a queue: one decision at a time, with the
question in words and the two or three plausible answers.

## What changed

### Entry by job, not by the system's taxonomy

Four questions with live counts — **Identify unknown files**, **Sort out
duplicates**, **Fix cover art**, **File problems** — replace six tabs named
Metadata / Duplicates / Cover art / Keeping both / Merged / All. None of those
was a thing anybody sets out to do.

An empty job is **dimmed, never hidden**: the controls must not shift between
visits. "File problems" keeps its slot on the user's instruction — *"Yes it does
get a spot. Eventually file problems will arise."*

`ReviewQueue` owns the grouping, and the four queries are **mutually
exclusive** — an item in two jobs means the counts lie and the same decision is
offered twice. Duplicates claim first; covers exclude a pending duplicate,
because deciding which copy to keep settles the cover too.

### A queue, not a table

A thin list on the left, one decision at a time on the right. The selection
lives in the URL, so a decision can be linked to and survives a refresh — a
queue you lose your place in is a queue you stop working. `J`/`K` move, `S`
skips.

### Each decision gets its own shape

A duplicate is a **side-by-side** of both copies with their paths, sizes and
formats. Cover art shows **the artwork**. Everything else is one question with
two or three answers. Forcing all three into table rows is why the old page
read as confusing.

### The page states the question in words

Every item opens with what is being asked and why a machine cannot answer it —
*"No album. Is this a single that never had one, or did the album tag fail to
read? Nothing can tell those apart."* The reason comes from `pipeline_error`
where the pipeline recorded one, because that text was already written for a
person.

### A Filament Page, not a Resource table

It stays in `/admin`: that panel is already gated on the current **profile's**
permissions, and reviewing means approving file moves, merging duplicates and
deleting files — exactly what the gate exists for. A kids profile must never
reach a merge button. Ten custom Filament pages with their own Blade views
already existed here, so this is the eleventh rather than a new architecture
(#482).

### Edition suffixes are surfaced, never stripped

`MediaItem::editionSuffix()` reads the `" - "` suffix that titles like
*Psycho Killer - Acoustic* and *1979 - Remastered 2012* carry. Three items in
the real queue have one. It is shown as **evidence** — labelled "kept, not
stripped" — because stripping it would merge recordings that must stay apart
(#476).

### Bulk only where it is safe

"Confirm all" exists for cover art, where every item's audio decision is
already made so confirming them together changes nothing on disk. There is
deliberately **no** bulk for duplicates: the detector refuses loose matches
outright (#461), and a button that cannot work is worse than none.

## Worth knowing

- **The old `DuplicateResource` is still routed, hidden from navigation.**
  Three test suites cover its behaviour, and retiring it in the same change
  would mean proving the new screen and rewriting those at once. A bookmarked
  URL keeps working. A follow-up retires it.
- No migration. Every job queries columns that already exist, including the
  `pipeline_state` and `pipeline_error` that #465 added.
- Counts on the Mac library: identify 83, duplicates 0, covers 0, files 0.
- Two bugs the tests caught rather than review: `MatchConfidence::getLabel()`
  does not exist (it is `label()`), and one test read an array key before
  assigning it. A third test asserted an intermediate pipeline state that a
  synchronous queue erases — it is now two tests, one with the queue faked and
  one that checks the real outcome.
