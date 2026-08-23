---
name: soundchex-check-what-a-field-means
description: Read what writes a column before concluding anything from its value; two fields in this project mean something other than their names suggest
metadata: 
  node_type: memory
  type: project
  originSessionId: ad369037-1b3f-4737-96d3-96dfa841d301
  modified: 2026-08-23T08:42:25.648Z
---

**Find the code that writes a column before drawing a conclusion from it.** Two
in this project mean something other than what their names imply, and both were
believed on 23 August 2026 with real consequences.

**`media_items.match_confidence`** is only ever set by the book and film/show
sources — `OpenLibrary` and TMDB. **No music source writes it**, so every music
row reads `none` for ever. The enum documents `None` as *"nothing matched;
metadata came from file tags or the filename"*, which for music is the normal,
correct outcome.

Reading `none` across 8,440 music rows as "nothing has ever been identified"
was wrong, and I told the owner so twice — including on a GitHub issue — before
checking. The library was in fact well tagged: **8,423 of 8,452 rows had an
artist**, 7,944 an album. The actual untagged count was **29**. The wrong
reading was about to send us after an AcoustID API key that nothing needed.

**`TransferRequest::publicState()`** returned `expired` only for a *pending*
request past its expiry. An **approved** one that had run out of time kept
reporting `approved` for ever, while `isUsable()` correctly refused it and the
server answered 401 to everything. A receiver polling the status believed it
and retried a dead token indefinitely (S-102).

**Why:** a column name is a summary written once, usually before the code grew
around it. `match_confidence` sounds like a property of every item and is a
property of two types. `publicState` sounds like the state and is the state
minus one case.

**How to apply:** `grep` for the write, not the read. One `grep -rn "column_name"
app/` would have prevented both, and took under a minute when finally done.
State it as measured, not as inferred — and if it has already gone out wrong,
correct it plainly where it was said. Related:
[[soundchex-break-the-test-to-trust-it]].
