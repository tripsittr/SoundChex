# Track numbers that are track numbers

Tracker #507.

Of 8,233 rows carrying a track number, **7,654 exceeded 100** — they were
filename index prefixes, not positions on a record. `906 Stressed Out.mp3`
stored as track 906. Only 579 were plausible.

Disc numbers had no such problem, so this was specific to the track field, and
`leadingInt()` accepted any integer with no upper bound.

It matters beyond tidiness. The review page answers *"no album — is this a
single?"* by reading the track number (#499), and `LibraryOrganizer` pads it
into the filename it files under.

## Two passes, and only one of them is a fact

**The ceiling** — anything above 100 is not a track. The longest plausible
single-disc running order is nowhere near it, and multi-disc sets carry a
separate disc number. This runs by default and cleared **7,654** rows.

**The filename match** — a stored value equal to the filename's leading index.
This is a *heuristic*, so it is opt-in behind `--match-filename`, and the first
version of it was **wrong in a way worth recording**.

It would have cleared 431 rows. Inspecting them before running it showed that
**162 were ordinary album numbering**: `02 Hang Me Up to Dry.mp3` stored as
track 2 is correct, and clearing it would have destroyed a right answer to tidy
up wrong ones. A match below track 30 is now taken as real, which leaves **269**
genuine indexes — `68 Impossible Germany.mp3`, `41 Someday.mp3`.

Both numbers come from the live library, read before anything was written.

## Nulled, not clamped

A wrong small number is worse than none: it reads as real, sorts an album into
nonsense, and nothing later can tell it was invented. Null leaves the field open
for a real tag read or a metadata source to fill.

## Verified

- **281 tests pass** across TrackNumber, FileTagger, Review and Library; 14 new.
- The reader's guard was checked by removing it — **3 of 9** reader tests fail
  without it.
- Run against the **live library**: 8,233 → **310** remaining, and the survivors
  are right (`02 Hang Me Up to Dry.mp3` → 2, `18 Don't Carry It All.mp3` → 18).

## Still open

The 310 that remain are *defensible*, not verified — no file in this library is
readable from this machine, so I could not compare a stored value against the
actual tag in the file. On a machine that holds the media, a re-read would
settle them. The field is now safe to trust in aggregate rather than proven
correct row by row.
