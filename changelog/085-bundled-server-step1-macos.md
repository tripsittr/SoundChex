# 085 — Bundled server Step 1: our own static PHP runtime, macOS built (S-151)

*2026-09-18.*

## Done

Produced SoundChex's **own** relocatable server runtime — no more dependence on
Herd's PHP. The macOS build is done and verified; Linux/Windows build in CI.

- **macOS (arm64) static PHP 8.4.25 + php-fpm**, built with static-php-cli 2.8.6
  with the audited extension set (bcmath, ctype, curl, dom, exif, fileinfo,
  filter, gd, gmp, iconv, intl, mbstring, opcache, openssl, pcntl, pdo,
  pdo_sqlite, phar, session, sodium, sqlite3, tokenizer, xml, xmlreader,
  xmlwriter, zip, zlib). Verified against the real app: Laravel 13.15.0 boots,
  `Schema::getColumnListing('users')` returns real columns, HTTPS via curl → 200,
  and the app serves end to end through **Caddy → our php-fpm**.
- **`server/`** — the runtime home: `templates/Caddyfile` + `templates/php-fpm.conf`
  (TCP listener, 25 MB cap, placeholder-substituted at launch), a `README.runtime.txt`,
  `THIRD-PARTY-LICENSES.txt`, and `scripts/package-runtime.sh` that assembles a
  relocatable bundle (php + php-fpm + Caddy 2.11.4 + cacert.pem beside php) and
  verifies the extension set. Output: `soundchex-server-macos-aarch64.tar.gz` (62 MB).
- **`.github/workflows/build-server.yml`** — builds all five targets
  (linux x86_64/aarch64, macos x86_64/aarch64, windows x86_64) on their own
  runners and attaches them to a `server-v*` release, since static-php-cli can't
  cross-compile.

## Notes / still broken

- **Extension audit corrected the plan.** The original list missed dom, iconv,
  json, libxml, phar, xml, xmlreader, xmlwriter, zip (all transitive
  `composer.lock` requires) and exif (getid3 artwork). Recorded in
  `BundledServerTasks.md`.
- **sqlite gotcha:** static-php-cli's *prebuilt* sqlite lib lacks
  `SQLITE_ENABLE_COLUMN_METADATA` (Laravel schema introspection needs it). Must
  build sqlite from source, not `--prefer-pre-built`. Full recovery steps in the
  tasks doc.
- **Windows has no php-fpm SAPI.** The Windows server must use `php-cgi` behind
  Caddy, not php-fpm — a real divergence to handle in Step 4, flagged in the
  workflow.
- The built binaries are **not** committed (60 MB each); they are release
  artifacts. Landing-site downloads wiring is the next change.
