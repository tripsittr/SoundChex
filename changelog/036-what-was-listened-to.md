# 036 — What was listened to

**Merged** 2026-09-11 · **Issues** S-117, S-118, S-119, S-120

Listening time was never recorded. It is now, and there is a statistics page in
the admin panel that reads it.

## What changed

### Listening time is recorded

`media_plays` held `position_seconds` — a resume *bookmark*, overwritten on
every save — so a track played twice to 3:00 was indistinguishable from one
played once. Nothing anywhere stored elapsed listening, and the only substitute
available was plays × duration, which counts a ten-second skip as a full
listen.

`listened_seconds` is nullable rather than defaulted to zero: the 505 rows that
predate it are honestly unknown, where a zero would claim they were played for
no time at all. `saveProgress()` accumulates the forward delta between saves.

**The seek guard is the load-bearing part.** The player reports at most every
10 seconds, so movement over 90 is a scrubber drag rather than playback.
Without the cap, one drag to the end banks the whole track and every chart
built on the column is wrong in the flattering direction — verified by removing
it, which turns a 10-second listen into 280.

Second fix, found while reading the same code: `recordPlay()` stamped only
`user_id`, so **84% of rows named an account and not a person**. A household
shares one login, which is exactly the case profiles exist for, and every
per-profile figure was reading one sixth of the data.

### A statistics page

**Library → Statistics**, custom Blade, gated on `View:MusicStatisticsPage`.

One date range and one profile filter at the top drive every figure on the
page, applied in a single private `plays()` builder — two sections disagreeing
about what "last 30 days" covers is how a statistics page stops being believed.
Each top list switches between a table and a chart.

What it shows: total library duration, listening totals and completion rate,
top 100 tracks / artists / genres / playlists, a listening clock by hour and
weekday, library vs listened, new against repeat by day, streaks, storage by
artist and genre, and a per-profile breakdown.

Seven Chart.js charts on Filament's own `ChartWidget` — already in the panel,
already used by `GenreSplit`, so no new dependency and no Apex plugin. Top
artists and storage are horizontal bars because artist names are long; genres
a doughnut because proportion is the question there; the clock and weekday
vertical bars; discovery and library-vs-listened stacked, since each pair is a
breakdown of one total rather than two series.

Laid out by weight rather than as seven equal boxes: discovery runs full width
because thirty days of stacked bars does not fit in half a screen, artists and
genres pair, and the two clock charts sit together. The headline figures are an
inline row rather than eight cards.

**Every chart says what it is showing.** A subtitle naming the window, whose
listening it is, and what it counts — "Last 30 days · everyone · plays per
artist, top 10" — plus axis titles and tooltips carrying the units. The artist
tooltip shows tracks and minutes beside the play count, because twelve plays
cannot otherwise be told from twelve skips; the genre doughnut shows each
slice's share, since a doughnut without percentages is decoration.

**No in-app disclosure banners.** Explaining to the owner of a private server
that 504 of his own plays predate a column belongs in a changelog, not on the
page. The unattributed row stays in the per-profile table as data.

The charts are Livewire components embedded in the view, keyed on the range and
profile so changing either remounts them. Widgets have their own lifecycle and
would otherwise never see the page's filters. Each repeats `canView()` rather
than trusting the page's gate: Livewire resolves a component by name, so a
chart is reachable without the page that hosts it.

## Worth knowing

- **Listening time starts 11 September 2026.** Older rows are null and are
  excluded rather than counted as zero. The page says so on screen when a
  window contains them, because a total that looks exact and is a floor is
  worse than one labelled as a floor.
- **Storage is a sampled estimate.** There is no `size_bytes` column at all
  (S-119), so the page samples 120 files and multiplies by the track count —
  108 resolved, averaging 5.0 MB. The alternative was 8,319 filesystem reads
  per page load.
- **"Top playlists" is inferred** from the plays of tracks on a playlist,
  because nothing records where a play came from (S-120). There are zero
  playlists on this install, so it shows an explanatory empty state.
- One migration, additive: a nullable column and a `(profile_id, created_at)`
  index. Backed up first; 505 rows unchanged.

## Still wrong

- **Three bugs the tests caught, worth repeating because each looked fine.**
  `diffInDays()` returns a *float* in Carbon 3, so `=== 1` never matched and
  every streak read as 1. `size_bytes` does not exist, so the first storage
  implementation queried a column that was never there. And `file_path` is
  relative to the storage disk for most rows, so reading it directly found
  zero files — it resolves through `absoluteFilePath()` now.
- **Per-user comparison has nothing to compare.** One profile, and 423 of 505
  plays predate attribution. It will fill in.

## Tests

- **PHP 502/502** — 14 new in `MusicStatisticsTest.php` and 7 in
  `ListeningTimeTest.php`. Checked for teeth: restoring the float comparison
  turns the streak test red (1 against 3), loosening the discovery join turns
  the repeat test red (2 against 1), and removing the seek cap turns three
  listening tests red.
- **Vitest 98/98**, unchanged — this is server-side.
- **Playwright** not re-run: no Blade or JS on the media-center side changed.
