# 022 — What today taught, written down

**Merged** pending

Rule 6.5 says publish what you learned in the same PR that taught it. Eight PRs
went out today without it. This is the arrears.

Three notes, all from things that went wrong rather than things that went well.

## `break-the-test-to-trust-it`

**Four tests written today could not have failed.** Each passed with the code it
was testing deleted, and each was only caught by deliberately breaking that
code:

- a compression test that re-implemented the compression instead of calling it;
- a "survives its own catalogue import" test where the row was never at risk
  under `:memory:`;
- an "ffprobe cannot answer" test that exited on an earlier branch and never
  reached the guard it named;
- a duplicate-keeper test with **one** candidate, where a preference cannot be
  got wrong because there is nothing to prefer.

The pattern is worth the note: a test written *after* a fix is written by
someone holding the fix's model, so it asserts what the fix already guarantees
rather than what was broken. All four looked reasonable and three read as
thorough.

The same shape turned up twice in monitoring — a stall detector that watched
only copied files reported a stall while a manifest built perfectly well, and a
dead queue worker was indistinguishable from a hung transfer because nothing
checked the worker.

## `windows-path-separators`

`Storage::path()` and `realpath()` produce different spellings of the same path
on Windows, and **every comparison using `DIRECTORY_SEPARATOR` was silently
broken there**. Four real bugs: the scanner recatalogued the entire library on
every scan (one film reached nine rows), the organiser stored a path nothing
else matched, `ConversionFiler`'s guard against flattening an organised library
did nothing at all, and a transfer rebuilt the source's filesystem inside the
library.

Three of those had been failing as tests for weeks and were read as a
separator quibble in the assertions. They were describing real bugs.

## `check-what-a-field-means`

`match_confidence` is written only by the book and film sources, so every music
row reads `none` for ever — which is the correct outcome for music, not a
failure. I read it as "nothing has ever been identified" and said so twice,
including on a GitHub issue, before checking. The library was well tagged:
8,423 of 8,452 rows had an artist and the real untagged count was **29**.

`TransferRequest::publicState()` was the same shape and cost a retry loop
against a dead token (S-102).

One `grep` for what writes the column would have prevented both, and took under
a minute when finally done.

## Tests

**451 PHP · 87 Vitest**, unchanged — documentation only. Suite green: 450
passing, 1 skipped, nothing failing.
