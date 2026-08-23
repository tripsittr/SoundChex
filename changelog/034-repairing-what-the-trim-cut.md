# 034 — Repairing what the trim cut

**Merged** 2026-08-23 · **Issues** S-115, GitHub #48

`library:repair-corrupt-titles`, for rows the byte-mask `trim()` left as
invalid UTF-8. #41 stopped new corruption and repaired nothing, which was the
right scope for it.

## What changed

### Titles are re-derived, not patched

The corruption is not reversible. `’Cause I’m a Man` became `<0x99>Cause I’m
a Man` — the two bytes that said which character it was are gone, and no
amount of inspection brings them back.

So the title is rebuilt from the **filename**, which the trim never touched.
It is the one surviving record of what the character was.

### It refuses to guess

- A row corrupt in any column other than `title` is **reported and left
  alone** — a filename cannot re-derive an artist.
- A row with no `file_path` is left alone. There is nothing to rebuild from,
  and the corrupt value is at least evidence of what happened.
- Every text column is checked, not just `title`. The bug was in a shared
  helper, so assuming it reached only one column is how the second one gets
  missed.

### Reports by default

`--apply` writes. Without it the command prints what it would do, showing the
leading bytes in hex — a corrupt title cannot be printed as text.

## Worth knowing

- **The Mac's catalogue is clean**: run here, it reports `No corrupt text
  found.` The 7 affected rows are on `a5`, above the 8,315-id ceiling of the
  imported catalogue, so they were written by that machine's own scanner and
  the transfer will neither repair nor remove them.
- So this command was written here and has to be **run there**.
- Four tests, and the repair one fails if the write is removed.
