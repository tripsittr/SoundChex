# 030 — Error pages are not audio

**Merged** 2026-08-23 · **Issues** S-110, GitHub #34

A four-minute source outage cost twelve files, and the reason they could not
simply be retried is a bug that had nothing to do with the outage.

## What changed

### A failed response no longer leaves its body in the part file

`sink()` writes the response body whatever the status, and the status check
happens after Guzzle has already written it. So a `500` left this on disk:

```
$ head -c 198 "59 Gloss of Blood - $uicideboy$.mp3.part"
{"message":"Server Error"}{"message":"Server Error"}…
```

33 bytes, six times over — one per attempt, because each retry resumes from
`filesize($temporary)` and appends.

**The next retry is the dangerous one.** With the source healthy it would ask
for `Range: bytes=198-`, receive genuine audio from byte 198, and produce a
file with an error page welded to its front. The hash check catches it, but
only after fetching the whole file again.

The body is now discarded on any non-success.

### What already arrived survives

Truncated back to the offset the attempt started from rather than deleted. A
resumed 4 GB film has legitimate bytes in front of the error page, and
throwing those away would cost the entire download — there is a 4.7 GB film
with 3.85 GB already on disk that this would have destroyed.

## Worth knowing

- Found by `ATLA5` inspecting the `.part` files before acting on a suggestion
  of mine that would have made things worse. The reset I proposed —
  `state => pending, attempts => 0` — would have resumed from the error bodies
  and produced corrupt files that looked like successful transfers.
- Both halves are tested, and both tests fail without the fix.
- No migration.
