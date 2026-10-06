# How many people voted

Part of #511 — the IMDb-depth half of the detail-page request, on the server
side so every client gets it.

OMDb returns **`imdbVotes`** on every lookup and nothing stored it. A score
without a count is much weaker than it looks: **9.3** could be three people or
three million, and IMDb never shows one without the other for that reason.

Stored as an unsigned integer, not the string OMDb sends. It arrives formatted
as `"3,235,958"`, and formatting is a decision each client should make against
its own locale rather than one baked into the database.

## The parsing is the risk

A naive `(int)` cast on `"3,235,958"` yields **3** — a number that looks
plausible, sorts wrongly, and would have read as "three people voted". Both new
tests fail against that cast.

`"N/A"` — OMDb's literal for absent — stores null rather than zero, because zero
reads as *"nobody voted"* where null reads as *"we do not know"*.

## Verified

- Against the **live OMDb API**: `Backrooms: rating=6.8 votes=179,011`.
- **32 tests pass** across the Omdb and MediaDetails suites; 3 new.
- Each checked by reverting the parser to a plain cast, which fails both.

## Not done

The rest of the IMDb-depth work is presentation over data that already exists —
the detail endpoint already returns 12 cast with characters and headshots, and
13 crew grouped by role. That is an iOS change, and two iOS branches are already
open and unreviewed, so it waits rather than stacking a third.
