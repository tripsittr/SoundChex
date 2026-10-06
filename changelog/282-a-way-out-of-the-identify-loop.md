# A way out of the identify loop

Tracker #499. Reported: *"the identify unknown files review page has me stuck in
a lookup again loop. We need a manual resolution option… the songs that I am
getting unknowns for look like duplicates for a couple, and all of them should be
easily taggable."*

Three faults, all of them in what the page said or offered.

## The page claimed nothing had recognised the file

The fallback text — *"No source recognised it, so filing it would mean guessing
at its artist and album"* — fired for **any** item no earlier branch matched,
regardless of what was actually known.

Measured on the live queue: **322 of 500** items had an artist, an album *and*
cover art while being told nothing had recognised them. One screenshot showed
**"Exact match"** in the badge and *"no source recognised it"* in the body, about
the same file.

The question now names what matched and why it still needs a person:

> **Is this the right match?**
> MusicBrainz matched this to "Georgia Sunshine" by Jerry Reed, but on the title
> alone rather than on an identifier, so it needs a person to confirm it.

The honest case survives: an item with no artist still says nothing recognised
it, and there is a test to keep it that way.

## The no-album question asked something the file answers

*"Is this a single that never had one, or did the album tag fail to read? Nothing
can tell those apart."* Except the tags do. A track number means the file came
off an album whose **title** did not read; nothing at all is what a standalone
file looks like on disk.

**30 of 39** no-album items on this library carry a track number, so the old
wording was wrong about **77%** of them. It now reads the number and says so.

## There was no way out

The only actions were accept a wrong match, repeat a lookup that returns the same
answer, or skip forever — because `reidentify()` re-runs the *same automated
query*, and the automated path picks one best match and discards the rest.

**Search by hand** opens a prefilled box (the title and artist already on screen,
since the usual fix is deleting a bracketed remix tag or correcting one word),
queries MusicBrainz with whatever is typed, and lists candidates with **album and
year** — the detail that tells two recordings apart. Choosing one writes the real
recording id, marks it `Exact` and stamps `reviewed_at`.

`Exact` is deliberate: a person choosing is a stronger claim than any string
comparison, and it is the confidence the filer requires before it will move a
file. Anything less would make the choice change nothing on disk.

## A bug the tests found

The first version marked the item complete and returned. On an item **with** a
review row that worked, because `ReviewLog::resolve()` resumes the pipeline. On
an item **without** one — which is most of them, 322 of 500 — nothing resumed it,
so `pipeline_state` stayed `waiting` and the item **stayed in the queue whatever
was chosen**: the same loop, one step further along.

The no-row path now resumes at `Filed`, and both paths close the modal, clear the
candidates and confirm. An early `return` had skipped all three, so a successful
choice looked like a dead button.

## Verified

- **42 tests pass**, 12 of them new. Checked by reverting each fix: all three
  `ask()` tests fail against the old wording, and the queue-clearing test fails
  without the resume.
- Exercised end to end against the **live library**: prefill, search, ten real
  candidates from MusicBrainz, choose one, item leaves the queue carrying a
  genuine recording id (`401c6f5e-498d-48f0-aee1-66bfbda2f932`).

## Not done

Only MusicBrainz is searchable by hand. Discogs would be the better source for
pressing-level choices, but nothing reads `discogs_token` yet — it is one of the
seven dead keys from #490.
