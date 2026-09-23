#!/usr/bin/env bash
# Rebuild and reinstall SoundChex Server.app in /Applications.
#
# Usage:
#   npm run reinstall:server
#   npm run reinstall:server -- --skip-build
set -euo pipefail

SKIP_BUILD=0

while [ $# -gt 0 ]; do
  case "$1" in
    --skip-build) SKIP_BUILD=1; shift ;;
    *) echo "unknown arg: $1" >&2; exit 2 ;;
  esac
done

OS="$(uname -s)"
[ "$OS" = "Darwin" ] || { echo "This installer currently supports macOS only." >&2; exit 2; }

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
APP_BUNDLE="$HERE/src-tauri/target/release/bundle/macos/SoundChex Server.app"
DEST="/Applications/SoundChex Server.app"

if [ "$SKIP_BUILD" = "0" ]; then
  echo "==> Building SoundChex Server.app"
  cd "$HERE"
  npm run build:server
fi

[ -d "$APP_BUNDLE" ] || { echo "Built app not found: $APP_BUNDLE" >&2; exit 1; }

echo "==> Installing to $DEST"
rm -rf "$DEST"
cp -R "$APP_BUNDLE" "$DEST"

echo "==> Installed"
ls -ld "$DEST"
stat -f '%N | modified: %Sm' "$DEST"
