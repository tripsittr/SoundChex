# 112 — Group music by the primary artist

*2026-09-19.* · **Issue** S-269

## Done

Tracks tagged with a feature — `artist` = "$uicideboy$, Pouya" or
"$uicideboy$/Maxo Cream" — were showing as **separate artists** from the lead,
scattering an artist's catalogue across dozens of near-duplicate entries. The
cause: `primary_artist` (the headline artist, used for grouping) was never set at
import, so 4,092 of 8,313 rows had it blank and everything grouped on the raw
credit.

- **At import:** `FileTagger` now derives `primary_artist` from the credit via
  `ArtistCredits` when it's blank. That parser keeps indivisible names whole —
  "Tyler, The Creator" and "Earth, Wind & Fire" are not split — and respects
  credit order, so "Pouya, $uicideboy$" files under Pouya.
- **Backfill:** `php artisan music:backfill-primary-artist` fills existing rows
  (`--all` recomputes every row, `--dry-run` previews). On the dev library it set
  all 4,092 blanks; "$uicideboy$" went from **52 distinct artist strings to the
  correct handful of primaries** (540 tracks under "$uicideboy$", the rest under
  whichever artist actually leads each collab).
- **API:** `MediaItemResource` now sends `primary_artist` (falling back to the
  full credit), so clients can group on it. The full `artist` credit is still
  sent for display.

## Worth knowing

- **Run the backfill after deploying:** `php artisan music:backfill-primary-artist`.
  New imports set it automatically; existing libraries need this once. On the dev
  library it has already been run.
- The web `AlbumBrowser` already grouped by `COALESCE(primary_artist, artist)`, so
  filling `primary_artist` fixes its grouping for free too.
- The featured credit itself is never rewritten — this only fills a separate
  grouping column.

## Still wrong / next

- The **iOS app** groups on this too (a matching change in the SoundChexiOS repo:
  `Meta.primaryArtist` + `groupingArtist`, used by the library album/artist
  grouping). The app also caches the library and can show stale grouping until it
  re-fetches (S-270).
- The duplicate detector's fuzzy match still compares the raw `artist`; using
  `primary_artist` there would let a "$uicideboy$" track and a "$uicideboy$,
  Pouya" copy of the same song match (S-271).

## Tests

PHP: **650 passing** (+6). New `PrimaryArtistTest` covers the backfill (derives
the primary, keeps indivisible names whole, fills only blanks by default, `--all`
recomputes) and the API (exposes `primary_artist`, falls back to the credit).
Pint clean.
