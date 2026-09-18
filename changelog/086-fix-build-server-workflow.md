# 086 — Fix the bundled-server CI workflow (S-151)

*2026-09-18.*

## Fixed

The first `build-server.yml` referenced a marketplace action that does not exist
(`crazywhalecc/static-php-cli-action`) — every job failed at "Set up job".

- **Rewrote the workflow to fetch the official `spc` binary** from
  `dl.static-php.dev` per OS and run the exact flow proven locally:
  `doctor --auto-fix` → `download` → `build --build-cli [--build-fpm]` →
  `package-runtime.sh`. No third-party action dependency.
- **Dropped `--prefer-pre-built`** so sqlite builds from source — the prebuilt
  sqlite lacks `SQLITE_ENABLE_COLUMN_METADATA`, which Laravel needs and spc's
  sanity check rejects. Slower on CI, correct and reproducible.
- **`package-runtime.sh`:** checksum the archive only (never the `.sha256`
  itself); verified `shasum -c` passes on the produced bundle.

## Update — Windows build (separate job)

Windows can't use the POSIX shape: PHP has **no php-fpm SAPI on Windows**, and
static-php-cli documents only **cli + micro** for Windows (no fpm, no cgi). Split
Windows into its own `build-windows` job running in **pwsh** (correct path
handling), building `php.exe` CLI (which still runs the queue worker, scheduler
and artisan) and packaging it with Caddy. The HTTP-serving front for Windows
(FrankenPHP embed, or a FastCGI shim) is deferred to Step 4's Windows service
work. `package-runtime.sh` now skips the php-fpm copy when php==fpm (Windows).
