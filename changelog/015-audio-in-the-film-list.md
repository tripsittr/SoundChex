# 015 — Audio in the film list, and a server talking only to itself

**Merged** pending · **Issues** S-93, S-94 · GitHub #16

Two of the three things raised in GitHub #16. The third — the music duplicates
— is planned in `Documentation & Planning/LibraryCleanup.md` and cannot be
acted on from this machine yet; see below.

## What changed

### The extension was a guess, and sometimes wrong

Three tracks sat in the film list:

```
Lights Out - Royal Blood
Aphex Twin live at Warehouse Project, Manchester
All The Kids Are Right - Local H
```

Not a classifier bug. They are `.mp4` files, and `.mp4` is configured as a film
extension, so the scanner did exactly what it was told. But `.mp4` carries
audio just as happily as video, and these came from a music export.

`ContainerProbe` asks ffprobe what is actually inside, and the scanner demotes
a film with no video stream to music. Two things it deliberately does not do:

- **It does not treat "cannot tell" as "no video".** ffprobe is optional here.
  A probe that failed and was read as an answer would recatalogue an entire
  film library as music the first time it went missing.
- **It does not count cover art as video.** An embedded sleeve is a video
  stream by `codec_type`; counting it would make every tagged track a film,
  which is worse than the bug being fixed.

Only `.mp4`, `.m4v`, `.mkv`, `.webm` and `.mov` are probed. An audio-only
`.avi` is rare enough not to be worth a process launch per file across
thousands.

`library:reclassify` gained the same check for rows already catalogued, since
the scanner only sees new files. It reports what it cannot check rather than
staying quiet about it.

### A5 was not down, it was listening to itself

```
LocalAddress  LocalPort      before: 127.0.0.1  8000
                             after:  0.0.0.0    8000

http://100.103.136.32:8000/up   before: FAILED   after: HTTP 200
```

`artisan serve --host 127.0.0.1` binds loopback, so the app answered on the
machine itself and nowhere else — including to the other machine on the
tailnet. Restarted on `0.0.0.0` with the owner's permission. That also exposes
it on the LAN; binding to the tailnet address alone would be tighter but stops
`127.0.0.1` working for local tools and the e2e suite.

## Worth knowing

- **The duplicate cleanup cannot run here yet.** Only 905 of 9,737 catalogued
  files are on this machine — the transfer has not copied the rest — and the
  `Sunnify` folder holding the suspected duplicates does not exist here at all.
  Deleting rows for files that live on another machine would break the
  catalogue further rather than clean it.
- The 1,064 duplicate `content_hash` values were measured in the *imported*
  catalogue, so they describe the source's library, not this one.

## Tests

**433 PHP · 87 Vitest.** 14 new.

Every guard was broken on purpose. Ignoring the probe turns the scanner test
red; treating "cannot tell" as "not a film" turns the command test red.

**One of those tests could not fail when first written.** The case it claimed
to cover — a film left alone when ffprobe cannot answer — exited earlier, on
the file-not-present branch, and never reached the guard. Breaking the guard
changed nothing. It now uses a file that is present with a failing probe, and
breaking the guard turns it red. Third time today that a test needed breaking
before it was worth anything.

**Still failing, and not from this change:** the same 6 failures and 1 error,
all S-86.

**Unverified:** the three tracks named above are still typed as films, because
their files are on the other machine. The fix is proven against faked probe
output, not against them.
