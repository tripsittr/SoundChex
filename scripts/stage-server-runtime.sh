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

[ -x "$BUNDLE/bin/php-fpm" ] || { echo "not a runtime bundle (no bin/php-fpm): $BUNDLE" >&2; exit 1; }

rm -rf "$DEST"
mkdir -p "$DEST/bin" "$DEST/templates"
cp "$BUNDLE/bin/"* "$DEST/bin/"
cp "$HERE/server/templates/Caddyfile" "$HERE/server/templates/php-fpm.conf" "$DEST/templates/"
echo "staged runtime into $DEST"
ls "$DEST/bin"
