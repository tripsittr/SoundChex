# 183 — Three bugs the cleanup found

**Merged** 2026-09-23 · **Issues** S-355, S-357, S-359

The three simplification passes each surfaced a real defect rather than a style
problem. All three are fixed here, each with a test that fails without the fix.

## `db:backup` could strand a copy of the database (S-359)

The command vacuums to `soundchex-<stamp>.sqlite`, gzips it, and deletes the
original. It opened both handles and then checked them together:

```php
$source = fopen($plain, 'rb');
$target = gzopen($compressed, 'wb9');

if ($source === false || $target === false) {
    return self::FAILURE;   // whichever handle opened is never closed
}
```

When exactly one open succeeded — a disk that fills as `gzopen` creates the
`.gz`, a permissions change between the two calls — the successful handle
leaked, and the uncompressed snapshot survived, because `unlink($plain)` only
runs on the success path. `prune()` globs `soundchex-*.sqlite.gz` and nothing
else, so it could never reap the orphan: on the daily schedule, every such
failure permanently kept a full-size copy of the database. That is precisely
what `prune()` exists to prevent.

Both handles are now closed and both files removed before returning. A short
`gzwrite` is also caught, which it was not before — a truncated `.gz` is worse
than no backup, because it looks like one and `prune()` would count it as good
and delete an older valid copy to make room.

`prune()` additionally sweeps stranded `soundchex-*.sqlite` files older than an
hour, so existing orphans are cleaned up. Hand-named snapshots
(`pre-migration-….sqlite`) are deliberately left alone — those are somebody's
safety net — and a recent one is left too, since it may be a run in flight.

## MusicBrainz could record an empty answer (S-355)

`fillBlank()` tested only whether the field was empty, where the same method on
the OpenLibrary and TMDB sources also tests `filled($value)`. So an empty value
could be written over a blank field: no better than before, but recorded as
though a source had answered. It was safe only because both call sites strip
empties first — safety at the call site rather than in the method, which a
third caller would not inherit.

## Disabling a plugin left its stylesheet behind (S-357)

`syncStyles()` resolved the plugin's directory with an `is_dir()` test before
branching, so disabling a plugin whose folder had been deleted returned early
and left the compiled stylesheet on disk — the opposite of what disabling is
for. Reading a plugin needs the folder to exist; cleaning up after one must
work precisely when it does not. Those are two questions and they have two
methods now. The traversal guard moved with it.

## Worth knowing

- **913 of 919** tests, the same two pre-existing failures. Ten new tests, each
  verified to fail against the unfixed code.
- No stranded snapshots existed on the development machine, so S-359 was latent
  rather than active.
