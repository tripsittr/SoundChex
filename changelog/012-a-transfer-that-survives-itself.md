# 012 — A transfer that does not delete itself

**Merged** pending · **Issues** S-89

011 got the catalogue into place. This is what happened next, on the real
transfer, seconds later:

```
The catalogue could not be renamed into place, writing over it instead
This catalogue was replaced by another server's
Call to a member function wants() on null   (RunTransferJob.php:93)
```

## What changed

### The transfer's record lives in the database it replaces

`transfers` and `transfer_items` are rows in the catalogue. The catalogue
import replaces that database wholesale. So the moment the catalogue landed,
the running transfer's own row and its **8,309-row work list** were replaced by
the *source's* transfer bookkeeping — records of copies the source was making,
which mean nothing on this machine.

`RunTransferJob` then did `Transfer::find($id)`, got null, and called
`wants()` on it. The catalogue imported correctly and the file copy could never
begin. The token lives in that row too, so nothing could resume either.

The transfer is now snapshotted before the swap and written back after it.
Anything occupying those ids came from the source and is replaced, because ours
is the one that is currently running.

`RunTransferJob` also stops rather than fatalling if the row is still missing.
"Should not happen" and a fatal two lines later are not the same thing,
especially when the catalogue is already in place by then.

## Worth knowing

- **This is a design smell, not just a bug.** A transfer's bookkeeping living
  inside the thing it is replacing will keep producing this shape of problem.
  Carrying the rows across fixes the case in hand; the durable answer is to
  keep transfer state outside the catalogue. Worth an issue of its own rather
  than a bigger change made in the middle of a live transfer.
- The receiver now shows the **source's** transfer history after an import,
  which is meaningless here. Not addressed.

## Tests

**407 PHP · 87 Vitest.** 1 new.

**The first version of that test could not fail.** It asserted the transfer row
was still there after the import — and in tests it always is, because Eloquent
runs on `:memory:` while the swap replaces a scratch *file*, so nothing was
ever at risk. Removing the fix left all 11 green. It asserts the carry-across
through its log line instead, and now removing the fix turns it red.

That is the second toothless test caught in this area by breaking the fix on
purpose, and the second that would have shipped without it.

**Still failing, and not from this change:** the same 6 failures and 1 error,
all S-86.

**Verified against the real transfer** for the failure, not for the fix: the
crash above is what transfer 9 did on this machine. The fix is covered by the
suite and has not yet been through a live import.
