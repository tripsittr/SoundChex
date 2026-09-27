#!/usr/bin/env bash
# Stage the bundled server runtime into src-tauri/runtime/ before `tauri build`
# of the Server app (S-151 Step 5). Takes a built runtime bundle dir (from
# server/scripts/package-runtime.sh) and copies its bin/ + templates/ into place
# so tauri.server.conf.json can package them as resources.
#
# Usage: scripts/stage-server-runtime.sh /path/to/soundchex-server-<os>-<arch>
set -euo pipefail
BUNDLE="${1:?usage: stage-server-runtime.sh <unpacked-runtime-bundle-dir>}"
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
DEST="$HERE/src-tauri/runtime"

# A Windows bundle has no php-fpm -- PHP ships no such SAPI there, and
# frankenphp.exe does both jobs (S-418). Accept either shape rather than
# rejecting the Windows bundle as "not a runtime bundle".
if [ ! -x "$BUNDLE/bin/php-fpm" ] && [ ! -f "$BUNDLE/bin/frankenphp.exe" ]; then
  echo "not a runtime bundle (no bin/php-fpm, no bin/frankenphp.exe): $BUNDLE" >&2
  exit 1
fi

rm -rf "$DEST"
mkdir -p "$DEST/bin" "$DEST/templates"
# Recursive: the Windows bundle keeps its dynamic extensions in bin/ext, and a
# flat copy silently left every one of them behind.
cp -R "$BUNDLE/bin/." "$DEST/bin/"
cp "$HERE/server/templates/Caddyfile" "$HERE/server/templates/php-fpm.conf" "$DEST/templates/"
echo "staged runtime into $DEST"
ls "$DEST/bin"
