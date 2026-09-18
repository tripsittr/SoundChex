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
