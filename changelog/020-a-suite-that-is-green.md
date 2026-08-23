# 020 — A suite that is green, and two real bugs it was hiding

**Merged** pending · **Issues** S-86

Six tests had been failing on Windows for long enough to be treated as
scenery. Every test report tonight ended "same 6 pre-existing failures", which
is exactly the state in which a real regression is invisible.

Two of the six were fixed by 018. **The remaining four were not test problems.**

## What changed

### The organiser stored a path nothing else could match

`LibraryOrganizer::toRelative()` strips the storage root off a `realpath()`
result. On Windows `realpath()` returns backslashes, so the stored value came
out `media\library\…` while every other stored path uses `media/library/…`.

That is the same fault as S-98, from the other end: the scanner could not
recognise a file it had already catalogued, and one film ended up with **nine
rows, one per scan**. The three failing `LibraryOrganizerTest` cases were
describing that, accurately.

Stored paths are forward-slashed now, whatever the platform.

### A guard against flattening the library did not work on Windows

`ConversionFiler` refuses to move an already-filed item into the flat library
root — "filing must never leave the library more disorganised than it found
it". The check compares the file's path against the library root using
`DIRECTORY_SEPARATOR`.

`Storage::path()` returns `C:\…\storage\app/private\media/library`: backslashes
for the root, forward slashes for the stored remainder. So the comparison never
matched on Windows, the guard returned "outside the library", and the filer
went ahead and flattened it.

**This one moves real files.** It was sitting behind a failure labelled as a
path-separator quibble in a test.

### A test that errored rather than failed

`HostServicesTest` writes to `~/Library/Logs/SoundChex/`, which always exists on
macOS and never on Windows. It now creates the folder if it is missing and
removes it again, along with the log it wrote — which it previously left behind
whenever the file had not existed. The tailing under test is not macOS-specific
and is worth running everywhere.

## Worth knowing

**446 tests, 445 passing, 1 skipped, nothing failing.** First time this suite
has been green on this machine.

That matters beyond tidiness. Four separate tests written today could not have
failed, and were only caught by deliberately breaking the code beneath them. A
suite with six permanent failures gives that habit nowhere to stand: "still the
same six" is indistinguishable from "and one new one".

## Tests

No new tests here — this is four existing ones going from red to green, three
of which were correct all along and one of which needed to clean up after
itself.

Both code fixes were checked by reverting them: the organiser one turns three
red, the `ConversionFiler` one turns its refusal test red.
