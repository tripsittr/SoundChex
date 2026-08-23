# 032 — A film that arrives in pieces

**Merged** 2026-08-23 · **Issues** S-113, GitHub #50

The only file over 1 GB in the library could not transfer at all. Six
attempts, and the `.part` never grew past what the first one fetched.

## What changed

### Large files are asked for in bounded pieces

`fetch()` asked for `bytes={$from}-` — everything from here to the end. For a
4.7 GB film with 952 MB outstanding, that is a single response the connection
cannot hold open long enough to deliver.

Measured against the real link before changing anything:

```
range asked         delivered      time
952 MB (to end)      27 MB         34.0s   ← died
 50 MB               24 MB         34.1s   ← died
 50 MB               28 MB         35.2s   ← died
 20 MB               20 MB         28.3s   ← completed
```

**A time limit on the connection, not a size limit on the range.** The same
request served over the loopback delivered all 952,599,863 bytes and the
`Content-Range` was correct, so the source was never at fault.

Each attempt now asks for at most 20 MB.

### A piece that lands is progress, not a short file

A `206` that leaves the `.part` short of `expected_bytes` puts the item back
in the queue rather than failing it, and does not count an attempt against it —
one file arriving in pieces is not one file failing repeatedly.

A `200` is still the whole file, so short still means truncated and still
fails. That distinction cost a real regression before it was caught:
`TransferReceiverTest::test_a_short_file_is_caught_even_without_a_hash` went
red, correctly.

## Worth knowing

- **No `.part` file is deleted by this.** The 3.85 GB already on `a5`'s disk is
  where the next chunk resumes from.
- The chunk size is a ceiling, not a quantum — anything under 20 MB is still
  one request, and there is a test asserting it gains no round trip.
- The owner suggested splitting the file, which is what this is. The
  measurement came after the suggestion and agreed with it.
