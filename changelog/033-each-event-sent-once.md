# 033 — Each event sent once

**Merged** 2026-08-23 · **Issues** S-114, GitHub #52

Devices re-sent their entire event buffer with every report, so 94% of what
the table held was repeats.

## What changed

### The buffer is trimmed after a successful send

`sendReport()` posted `log.slice(-MAX_EVENTS)` and never removed what it had
sent, so each report carried everything since the tab opened.

Measured independently on both machines:

```
device_reports rows       125
event objects stored    2,306
distinct events           140
=> 94% repeats
```

Sent events are now dropped, matched by identity rather than by count — the
batch is the *tail* of the buffer, so a count-based slice would drop from the
wrong end once the buffer exceeded `MAX_EVENTS`, and anything recorded while
the request was in flight sits at the tail too.

**A failed send keeps everything.** A dropped report must not also lose the
evidence it was carrying.

## Why it mattered more than the wasted rows

`device_reports` is the only instrument for a phone that is not in the room.
One incident arriving in three consecutive reports reads as three incidents:
`a5`'s log watcher announced four separate failures in ten minutes when there
had been one, with byte-identical client timestamps across all of them.

An instrument that inflates what it measures is worse than a quiet one.

## Worth knowing

- No migration, and existing rows are not rewritten. This stops new
  duplication; the 2,306 stored events stay as they are.
- Both halves are tested and the first fails without the fix.
