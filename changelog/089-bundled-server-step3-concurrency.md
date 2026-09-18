# 089 — Bundled server Step 3: concurrency audit + atomic .env write (S-151)

*2026-09-18.*

## Done

php-fpm runs requests in parallel; `artisan serve` never did. Audited every
place that quietly relied on that. The picture was better than feared, with one
real bug fixed.

- **Already safe (no change needed):**
  - SQLite is `WAL` + `busy_timeout=120000` — simultaneous readers/writers, which
    is exactly what concurrent workers need.
  - Sessions use the **database** driver (no file-session lock contention).
  - `NetworkAddresses` keeps probe results in the **cache** (atomic), not a file.
  - `ProbeNetworkJob` / `network:probe` are out-of-band by design — they exist
    *because* `artisan serve` is single-threaded, and work fine under php-fpm.
  - The connection watcher is client-side JS, origin-agnostic.
- **Fixed — `EnvironmentFile::set()` was not concurrency-safe:** a lockless
  read-modify-write of `.env`. Two writers (a ServerSettings save + the scheduled
  `server:detect-address`) could interleave and clobber a key or tear the file.
  Now holds an exclusive `flock` across the whole read-modify-write and replaces
  the file with an **atomic temp-file rename**, so a reader sees old-or-new,
  never partial.

## Notes

- New `EnvironmentFileTest` covers update, append, and atomicity (no torn file,
  no leftover temp). 21 network/env/session/health tests pass.
- `EnvironmentFile::path()` went `private` → `protected` so a test can point it
  at a throwaway file.
