# 018 — Every scan catalogued the whole library again

**Merged** pending · **Issues** S-98, S-99, S-95 (part), S-86 (part) · GitHub #16, #18

"There are tons of dupes all over the library. I don't know if it's just
displaying them as such."

Both, and neither was what it looked like. Measured:

```
1. paths catalogued more than once      : 792   (1,239 redundant ROWS, no file to delete)
2. content at more than one path        : 384   (a redundant FILE each)
3. same title, provably different files : 1,425 (not duplicates at all)
```

## What changed

### The scanner could not recognise a file it had already seen (S-98)

One film held **nine catalogue rows** — same path, nine times:

```
9x  media\library\Movies\Jackass Number Two (2006)\Jackass Number Two (2006).avi
```

The scanner keys its "already known" list on `Storage::path()`, which builds
`C:\…\storage\app/private\media\…` — mixed separators — and looks it up with
`getRealPath()`, which returns `C:\…\media\…`. The same file, spelled two ways,
never equal as strings. So **on Windows every scan catalogued the entire
library again**, and nine rows means nine scans. It would have kept growing.

Both sides go through one canonical spelling now. Case is folded only where the
filesystem ignores it: on Linux `Song.mp3` and `song.mp3` are two files and
folding there would merge them.

This is most of what "tons of dupes" actually was, and it is why they are "all
over" rather than in one place.

### Resolving a duplicate did not make it disappear (S-99)

`DuplicateDetector::merge()` keeps the duplicate row deliberately — play
history and ratings live on it — and repoints it at the surviving file. Nothing
hid it afterwards. So merging nine rows left nine rows on screen, and resolving
duplicates looked like it had achieved nothing.

Merged rows are now filtered in `ContentGate::apply()`, which is the one call
every browse and search query already makes. Putting it in `MediaBrowser`
instead would have meant adding it to eight query sites — which is how a gate
ends up applied in seven of them, the failure that class exists to prevent. The
duplicate review screen queries the model directly and still sees everything,
which is what it needs.

Rows still awaiting review, and pairs deliberately kept, stay visible. Nobody
has decided about those.

### The `#` titles

Ten rows titled `#`, from files named `45 - #.mp3`, `46 - #.mp3`. They are five
real files catalogued twice each. The title is the filename, because nothing in
this library has ever matched a metadata source — all 8,440 music rows carry
`match_confidence = none`. That is S-95's remaining strand and is not fixed
here.

## Worth knowing

**Two of the six long-standing Windows failures (S-86) are now green.**
`LibraryScannerTest::test_a_stored_relative_path_still_counts_as_known` and
`test_a_converted_copy_is_not_catalogued_as_its_own_film` were failing for
exactly this reason — they were describing the bug, not a test problem. Four
failures and one error remain, all in `LibraryOrganizer`, `ConversionFiler` and
a macOS-only log path.

**1,425 repeated titles are not duplicates.** Different files, same unhelpful
title. Nothing here touches them, and nothing should until metadata works.

## Tests

**442 PHP · 87 Vitest.** 6 new.

Neutering the canonical spelling turns two red; showing merged rows again turns
one red.

**Unverified against the real library.** The scanner fix stops *new* duplicate
rows; the 1,239 that already exist are cleaned by `library:duplicates --merge`,
which has not been run — the files are still on the other machine.
