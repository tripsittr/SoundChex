# A read-only file cannot be unlinked on Windows

`unlink()` fails on a file carrying the Windows read-only attribute. It is not a
permissions error you can see from `is_file()` or `filesize()` — both succeed,
and only a write test (`fopen($path, 'r+b')`) reveals it.

On the real library **285 of 2,843 files** were read-only, arrived that way from
a copy off another machine. `War Dogs (2016).mkv` reports
`ReadOnly, Archive, SparseFile`. Every duplicate merge touching one of those
failed, the row stayed `pending`, and the next sweep flagged it again — reported
to the user as "a file was missing or the contents no longer match", about a
file that was present throughout. That is the whole of "I merged it and they
came back".

Clear the attribute, then retry, and say so:

```php
if (@unlink($path)) {
    return true;
}

if (@chmod($path, 0666) && @unlink($path)) {
    Log::info('Cleared the read-only attribute to delete a duplicate', ['path' => $path]);

    return true;
}
```

Only after the caller has decided to delete — never make a file writable
speculatively.

## Two related lessons

**Only the duplicate delete path knows about this.** Anything else that writes a
library file in place — retagging, cover embedding, transcoding over the
original — can still fail on those 285.

**One message for three outcomes hides the cause.** "Skipped" covered merged,
tied, and could-not-delete, and named a reason that was none of them. Count the
outcomes separately and log the real one; a `catch` that only shows a toast says
something broke and not what.
