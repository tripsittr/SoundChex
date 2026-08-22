# Music experience

Rebuild the music side of the media centre to work the way a music app works,
rather than the way a film library works with songs in it.

## What is wrong now

Judged from screenshots at 390px, which is where it is actually used.

**The Music tab** opens on a hero image occupying roughly 60% of the screen for
a single track, whose primary button is "View details" rather than Play. The
groupings — Songs, Albums, Artists, Genres, Playlists — sit at the *bottom* of
the page, above the tab bar, where they read as a footer rather than as the
navigation they are. Recently Added is a two-item rail below the fold.

**Albums** spends three stacked rows on chrome — a page title, a count, and the
sub-nav — before a single album appears. The desktop footer ("Self-hosted media
library", "Library management") renders on a phone underneath everything.

**Album detail** stacks its actions in three separate rows: Play and Shuffle,
then Download on its own line, then a centred "More" floating alone. There is
no artwork colour, and the artist is not a link. Track order was wrong — see
below.

**Underneath all of it**, the music screens are the film screens with different
data. A film library is a grid of posters you browse; a music library is lists
you play from. The hero, the poster grid and the "View details" affordance are
all inherited from the wrong medium.

## One real bug, fixed already

Album tracks listed in reverse. `sortBy()`'s array-of-closures form compares
each closure in turn, and a `null` from `discNumber()` — which is what an
album with no disc tag gives, i.e. most of them — reversed the pair instead of
falling through to the track number. Replaced with a single spaceship
comparator, and covered by a test.

## What the real apps do

Common to Spotify, Apple Music, Amazon Music and SoundCloud:

- **Play is the primary action, everywhere.** Every row, tile and header has a
  play affordance. Nothing routes through a "details" page first.
- **The library is one screen with filter chips**, not five destinations. The
  chips sit directly under the title, not at the foot of the page.
- **Artwork carries the identity**, and its colour bleeds into the header.
- **Rows, not grids, for tracks.** Grids for albums and artists, where the
  cover *is* the item.
- **Actions are one row**: a large Play, a Shuffle, then everything else behind
  a single overflow menu. Never three stacked rows of buttons.
- **The now-playing bar expands to a full player**, which this already has.
- **Search is prominent and instant**, filtering as you type.

## Plan

Ordered so each step stands on its own.

### 1. One library screen (~1 day)

Replace the five music destinations with a single screen whose chips filter it.
The chips move directly under the header. Songs, Albums, Artists, Genres and
Playlists become filters, not pages — the URLs stay valid so nothing breaks and
links keep working.

### 2. Kill the hero on music (~half a day)

A hero image is a film-library idea: it sells one title. A music library opens
on what you were listening to and what is new. Recently played, then recently
added, then the library itself.

### 3. Album and artist pages (~1 day)

One action row: Play, Shuffle, overflow. Artwork colour extracted for the
header background. Artist as a link. Track rows already carry their own
actions; the header stops duplicating them.

### 4. Rows everywhere, grids only for covers (~half a day)

Songs are rows with artwork, title, artist, duration and a kebab. Albums and
artists stay as grids, because there the cover is the thing.

### 5. Instant search (~half a day)

Filters as you type against the mirror, which is already on the device. The
server is only needed for dialogue and page text, which the UI says plainly.

**Total: ~3–4 days.** No new data model, no migrations — every one of these is
a view change over data that already exists.

## Deliberately not doing

- **A separate music app.** One codebase, one navigation. Music gets its own
  *shape*, not its own silo.
- **Copying any one app exactly.** These conventions are shared because they
  work; the goal is a music library that behaves as expected, not a clone.
- **Touching the film, TV or book screens.** A poster grid is right for those.
  The complaint is that music inherited it, not that it is wrong everywhere.

## Not started

Nothing here is built beyond the ordering fix.
