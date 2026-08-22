# Unified Search

One search box that reaches everything in the library — including inside the
books and the dialogue of the films.

## Why this one

Commercial services cannot do this. Netflix doesn't hold the book, Spotify
doesn't hold the film, and none of them will index a novel's text so you can
find the scene it became. A self-hosted library holds all of it at once, and
already has most of the index built.

## What exists

| Source | State |
|---|---|
| Titles, artist, album | Searched today, in `MediaBrowser::search()` |
| Book page text | **1,346 pages indexed** in `page_texts` |
| Subtitle dialogue | **2,879 cues**, but only as `.vtt` files on disk |
| People (cast, crew, authors) | 57 rows, not searched |
| Tags and genres | 732 rows, not searched |
| Book author, publisher, ISBN | Stored, not searched |

Current search covers only titles and music metadata. Everything else in that
table is invisible to it.

## The one real decision

**Subtitle cues are in files, not the database.** Grepping 2,879 cues across
two files is instant; across a real TV library it would not be, and it can't be
ranked or paginated.

So: a `subtitle_cues` table, populated when a track is imported. It costs a row
per cue — a feature-length film is roughly 1,500 — which is small next to the
video itself. `SubtitleImporter` already parses cues to count them, so this is
storing what it currently discards.

## Scope

**In:**

- A `SearchService` that queries every source and returns ranked, grouped
  results
- `subtitle_cues` table + backfill for existing tracks
- Search across people, tags, book author/publisher/ISBN
- A results page grouped by kind, with deep links: a cue jumps to its timestamp,
  a page hit opens the reader there
- Cross-media links on the detail page: the book beside its film adaptation

**Out:**

- Fuzzy or phonetic matching. `LIKE` is enough for a personal library and is
  honest about what it does.
- A search index engine (Scout, Meilisearch). It's another service to run on a
  home machine for a library this size.
- Search-as-you-type across everything. The existing quick search stays as it
  is; this is the full results page.

## Approach

1. `subtitle_cues` migration and model. Populate on import; backfill existing.
2. `SearchService` with one method per source, each returning a common shape:
   kind, item, label, snippet, deep link.
3. Ranking: exact title, then title prefix, then people/tags, then full text.
   A title match must never rank below a passing mention in a subtitle.
4. Results page grouped by kind, each group collapsible, capped per group with
   "show all".
5. Cross-media links via normalised title + creator matching, shown only where
   confident — a wrong link is worse than none.

## Constraints

- **`ContentGate` applies to every query.** A kids profile must not find an
  R-rated film through a line of its dialogue. This is the exact failure mode
  the gate exists for.
- Cue text is user content and goes through the same escaping as book snippets:
  marked with sentinels server-side, turned into elements client-side, never
  interpolated as HTML.
- Backfill must be resumable and must not re-parse tracks it has already done.

## Verification

- Search a word appearing only in book text → the book, at the right page.
- Search a line of dialogue → the film, at the right timestamp.
- Search a cast member → their titles.
- A kids profile finds none of the above for a capped title.
- Title matches rank above incidental full-text hits.
- Results page renders; every deep link lands in the right place.

## Outcome

Built and verified, except cross-media links (see below).

- `subtitle_cues` holds 2,879 lines from the existing tracks, indexed on import
  and backfillable with `php artisan subtitles:index`. Re-indexing is
  idempotent.
- `SearchService` covers titles, metadata, people, dialogue, book pages and
  tags, grouped and ranked so an exact title beats an incidental mention.
- Results page renders each group in the shape that suits it: posters for
  titles, chips for tags, snippets with a destination for dialogue and pages.
  A dialogue hit links to `?t=<seconds>`; a page hit to `#page=<n>`.

**A real bug found here:** `ContentGate` filtered on an unqualified `type`
column, so applying it to any joined query produced "ambiguous column name" and
crashed. That affected the existing tag rail too, not just search — it is
qualified now.

Dedup on dialogue was necessary: a film with both a standard and an SDH track
stores every line twice, and the same moment listed twice is noise.

Verified: dialogue search returns the right timestamps; book text returns the
right pages; a kids profile finds neither for a capped title, and cannot reach
it through a line of its dialogue; markup inside a cue is escaped rather than
rendered; tests 9/9.

## Deferred

**Cross-media links** — pairing a book with its film adaptation. Left out
deliberately: the current library has one film and no TV, so any matching rule
would be written against a sample of one and verified against nothing. Worth
doing once the library holds a book and its adaptation together.
