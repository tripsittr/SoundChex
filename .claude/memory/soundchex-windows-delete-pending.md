---
name: soundchex-windows-delete-pending
description: On Windows, unlinking a file while a handle is still open poisons the name — every later open fails with "Permission denied" until the holding process exits
metadata:
  type: project
---

`unlink()` on Windows does not remove a name that something still has open. It
marks the file **delete-pending**: it vanishes from `file_exists()`, stays in
the directory listing, and every later attempt to open that name is refused
with `Permission denied` until the last handle closes.

Reproduced on 22 August 2026 with PHP 8.4 on this machine:

```
unlink returned: true
file_exists after unlink: false
reopen while handle open: false   ← Failed to open stream: Permission denied
after closing the sink, reopen:  true
```

It bit the server transfer. `TransferReceiver::importDatabase()` unlinked the
downloaded archive while the Laravel HTTP response — which holds the Guzzle
`sink` file open for as long as it is alive — was still in scope. The queue
worker is long-running, so the name stayed poisoned and **every later transfer
failed before it had contacted the source**, with an error that reads like a
permissions misconfiguration and is not one.

**Why:** the error names the wrong thing. `Permission denied` on a directory
the process demonstrably can write to sends you looking at ACLs, antivirus and
Controlled Folder Access. The tell is that the *directory* is writable and only
one filename is refused, and that `Get-Acl` and `icacls` on that file also
return access denied while `[System.IO.File]::GetAttributes()` still works.

**How to apply:** close handles explicitly before unlinking or reopening a
path, rather than relying on refcount to do it at end of scope — and give
per-run temporary files per-run names, so one stuck name cannot block
everything after it. Both, not either: the second is what keeps a failure from
cascading.

`Http::fake()` cannot reproduce this — it writes sinks with
`file_put_contents`, which closes immediately. Verify it by reproduction; the
suite can only pin the unique-name half. See
[[soundchex-testing-standard]] on what a test that cannot fail is worth.
