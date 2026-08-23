# 009 — A third of the films were television

**Merged** pending

Reported as "not classifying movies and shows properly". Measured against the
real library: **50 of 159 items catalogued as films are television.**

## What changed

### One value answering two questions

`EpisodeParser::parse()` decided both of these:

- **Is this television?** — which sets the item's type.
- **Can this be filed?** — which decides whether a real file gets moved.

They are not the same question, and the second is deliberately strict: a file
filed as the wrong episode looks correct and is far harder to notice than one
left in the inbox. So `parse()` refuses season zero, because specials have
their own filing rules.

The scanner read that refusal as "not a show". Every special was therefore
catalogued as a **film** — all 48 Simpsons season-zero shorts in this library.

Split in two. `marker()` and `isTelevision()` answer the classification
question and are broad. `parse()` answers the filing question and is
**unchanged** — everything it refuses is still refused, because everything it
refuses is a file that would otherwise be moved somewhere wrong.

### A form that matched nothing

`The Simpsons S06X01` — the season prefix with an `x` separator. The patterns
covered `S01E02` and `1x02` but not this, so it matched nothing and stayed a
film. That is the other 2 of the 50.

It is unambiguous in the same way `S06E01` is, so it was added to the filing
patterns too, not just the classification ones.

### 48 rows called "The Simpsons"

Because the title came from the filing result, every special was catalogued
under the bare series name — 48 rows with nothing to tell them apart. Titles
now come from the marker, so a special carries its own `S00E07`.

### Repairing what is already catalogued

The scanner skips files it has already seen, so **fixing the classification
fixes nothing already in the library**. `library:reclassify` repairs those
rows:

```bash
php artisan library:reclassify           # reports, changes nothing
php artisan library:reclassify --apply   # writes
```

It touches `type`, `title` and nothing else. No file is moved: what these items
*are* is wrong, where they sit on disk is not.

## Worth knowing

- **The repair has not been applied.** The dry run was run against the real
  library and found exactly the 50, leaving the 109 genuine films alone — but
  `--apply` rewrites rows in the only copy of a real catalogue, and the
  standing rule here is to ask first. One command, and it is the owner's to
  run.
- **Run `library:scan` afterwards** to attach the reclassified specials to
  their series row. The command does not create rows.
- The other direction is tested as hard: a film retyped as a show is a film
  that disappears out of the films, which is the more expensive mistake.

## Tests

**403 PHP · 87 Vitest.** 18 new, all passing.

Both guards were broken on purpose. Making `isTelevision()` defer to `parse()`
again — the original bug — turns **6** red. Removing the `SxxXyy` pattern turns
**3** red.

**Still failing, and not from this change:** the same 6 failures and 1 error as
008, all S-86 — `/` against `\` on Windows, and one macOS-only log path.

**Playwright was not run.** No `.env.e2e` on this machine. Nothing here reaches
a browser, so the two suites that could catch it are the two that ran.
