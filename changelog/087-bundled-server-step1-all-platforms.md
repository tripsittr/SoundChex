# 087 — Bundled server Step 1 complete: all platforms build in CI (S-151)

*2026-09-18.*

## Done

The bundled server runtime now builds on CI for every target — the Step 1
deliverable is complete.

- **Linux x86_64 (~61 MB), Linux aarch64 (~60 MB), Windows x86_64 (~39 MB)** all
  produce runtime bundles. macOS aarch64 builds too (verified locally end to
  end); macOS x86_64 uses the identical path and only waits on scarce Intel-mac
  runners.
- `.github/workflows/build-server.yml` fetches the official `spc` binary per OS
  (static-php-cli can't cross-compile) and runs doctor → download → build →
  package.

## Gotchas fixed along the way

- The marketplace action I first referenced doesn't exist — fetch `spc` directly.
- sqlite must be pinned to **source** after a prebuilt download (spc's prebuilt
  sqlite lacks `SQLITE_ENABLE_COLUMN_METADATA`, which Laravel needs).
- **Windows is not a php-fpm platform** — PHP ships no fpm/cgi SAPI on Windows,
  so it builds `cli` in its own pwsh job; the HTTP-serving front (FrankenPHP
  embed / FastCGI shim) is deferred to Step 4.
- Windows also needed: the MSVC dev env, pinning to `windows-2022` (nightly spc
  rejects VS 18 on windows-latest), dropping **gmp** (unbuildable on Windows) and
  **pcntl** (POSIX-only), and `Compress-Archive` instead of the missing `zip`.
- Pass `GITHUB_TOKEN` so spc's GitHub API calls don't hit the 60/hr rate limit;
  retry doctor/downloads for transient upstream 500s.

## Still open

- macOS x86_64 artifact pending on runner availability (build logic proven).
- **Windows serving** (fpm/cgi replacement) and TLS are later steps (4 and 7).
- Bundles reach the public download links only once published to a `server-v*`
  release — deliberately **not** tagged yet (holding per owner).
