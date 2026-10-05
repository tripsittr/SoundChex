# Files the scanner refused, and said nothing about

Five music files sat in the inbox for four months. `library:scan` found them
every five minutes, decided it could not read a title out of the filename, and
skipped each one with a bare `continue` — not counted, not logged. The command
printed nothing and exited 0, which is indistinguishable from an inbox with
nothing new in it.

Two separate bugs put them there, and a third hid both.

## A book heuristic was eating music titles

```php
$title = preg_replace('/\s+by\s+[^-–—]+$/i', '', $title) ?? $title;
```

That exists for books — "Dune by Frank Herbert" — and ran on every media type.
On `01 - By My Side` it matched `" By My Side"`, stripped it, and left `01 -`,
which then read as a filename with no title in it and was dropped.

Worse than dropping: it also **truncated titles that merely contain the word**.
`Stand by Me` was stored as `Stand`. One row in the real library is titled
"Stand", from a file called `Stand By Your Man`, and a book is cut at
`MONEY Master the Game -`. Two rows of the 48 whose filenames contain " by ".

Now books only, and the pattern requires something before the "by", so the
clause has to be an author clause rather than the whole title. A book called
*By Grand Central Station* keeps its name.

## The generated-name guard counted bytes

```php
if (! str_contains($title, ' ') && strlen($title) > 24)
```

That guard catches Livewire temp uploads, which are long unbroken runs of mixed
case. `D‐I‐V‐O‐R‐C‐E` is thirteen characters — but its hyphens are U+2010, three
bytes each, so `strlen` measured twenty-five and threw it away as a hash. Any
title carrying accents or typographic punctuation was liable to the same thing.
`mb_strlen` now.

## And the silence

A file the scanner cannot classify is counted as `unreadable`, its path
recorded, and a warning logged saying what to do about it. Before, it was a
bare `continue` — the one case where the scanner gives up on a file was the one
case it never mentioned.

## What this does not fix

- **The two damaged titles are still damaged.** The fix stops new truncation; it
  does not repair rows already written. `id=6554` ("Stand", from
  `Stand By Your Man`) and `id=53` need re-enrichment or a manual correction.
- **Subfolders were never the problem.** Worth recording since it was the first
  suspicion: Symfony Finder recurses by default and there is no `depth()` limit,
  and on the real library all 33 media files inside subfolders of `unsorted` are
  catalogued.
