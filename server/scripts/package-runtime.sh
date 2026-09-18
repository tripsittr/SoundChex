#!/usr/bin/env bash
# SoundChex bundled server — package a per-OS runtime bundle (S-151 Step 1/6).
#
# Assembles a relocatable server runtime from:
#   - a static PHP + php-fpm built by static-php-cli (spc)
#   - the official Caddy release binary
#   - a CA bundle placed BESIDE the php binary (TransferReceiver.php:1275 finds
#     it as dirname(PHP_BINARY)/cacert.pem)
#   - the Caddyfile / php-fpm.conf templates and THIRD-PARTY-LICENSES.txt
#
# Native only: spc cannot cross-compile, so run this on each target OS (locally
# for macOS, in CI for Linux/Windows — see .github/workflows/build-server.yml).
#
# Usage:
#   server/scripts/package-runtime.sh <os> <arch> <php-bin> <phpfpm-bin> <out-dir>
# e.g.
#   server/scripts/package-runtime.sh macos aarch64 .../buildroot/bin/php \
#       .../buildroot/bin/php-fpm dist/
set -euo pipefail

OS="${1:?os (macos|linux|windows)}"
ARCH="${2:?arch (aarch64|x86_64)}"
PHP_BIN="${3:?path to built php}"
FPM_BIN="${4:?path to built php-fpm}"
OUT_DIR="${5:?output dir}"

CADDY_VERSION="2.11.4"
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"   # server/
NAME="soundchex-server-${OS}-${ARCH}"
STAGE="${OUT_DIR}/${NAME}"

echo "==> packaging ${NAME}"
rm -rf "$STAGE"
mkdir -p "$STAGE/bin"

# --- PHP + php-fpm --------------------------------------------------------
EXE=""
[ "$OS" = "windows" ] && EXE=".exe"
cp "$PHP_BIN" "$STAGE/bin/php${EXE}"
cp "$FPM_BIN" "$STAGE/bin/php-fpm${EXE}"
chmod +x "$STAGE/bin/php${EXE}" "$STAGE/bin/php-fpm${EXE}" 2>/dev/null || true

# --- CA bundle beside php (load-bearing convention) -----------------------
echo "    fetching cacert.pem"
curl -fsSL -o "$STAGE/bin/cacert.pem" https://curl.se/ca/cacert.pem

# --- Caddy (official release static binary) -------------------------------
case "${OS}_${ARCH}" in
  macos_aarch64)  CADDY_OSARCH="mac_arm64" ;;
  macos_x86_64)   CADDY_OSARCH="mac_amd64" ;;
  linux_aarch64)  CADDY_OSARCH="linux_arm64" ;;
  linux_x86_64)   CADDY_OSARCH="linux_amd64" ;;
  windows_x86_64) CADDY_OSARCH="windows_amd64" ;;
  *) echo "unsupported os/arch: ${OS}_${ARCH}" >&2; exit 1 ;;
esac
echo "    fetching caddy ${CADDY_VERSION} (${CADDY_OSARCH})"
CADDY_TMP="$(mktemp -d)"
CADDY_EXT="tar.gz"; [ "$OS" = "windows" ] && CADDY_EXT="zip"
curl -fsSL -o "$CADDY_TMP/caddy.${CADDY_EXT}" \
  "https://github.com/caddyserver/caddy/releases/download/v${CADDY_VERSION}/caddy_${CADDY_VERSION}_${CADDY_OSARCH}.${CADDY_EXT}"
if [ "$CADDY_EXT" = "zip" ]; then
  unzip -q -o "$CADDY_TMP/caddy.zip" -d "$CADDY_TMP"
else
  tar -xzf "$CADDY_TMP/caddy.tar.gz" -C "$CADDY_TMP"
fi
cp "$CADDY_TMP/caddy${EXE}" "$STAGE/bin/caddy${EXE}"
chmod +x "$STAGE/bin/caddy${EXE}" 2>/dev/null || true
rm -rf "$CADDY_TMP"

# --- config templates + notices -------------------------------------------
cp "$HERE/templates/Caddyfile" "$STAGE/Caddyfile"
cp "$HERE/templates/php-fpm.conf" "$STAGE/php-fpm.conf"
[ -f "$HERE/THIRD-PARTY-LICENSES.txt" ] && cp "$HERE/THIRD-PARTY-LICENSES.txt" "$STAGE/"
[ -f "$HERE/../LICENSE" ] && cp "$HERE/../LICENSE" "$STAGE/LICENSE"
cp "$HERE/templates/README.runtime.txt" "$STAGE/README.txt" 2>/dev/null || true

# --- sanity: the built php reports the required extensions ------------------
echo "==> verifying extensions"
REQUIRED="curl intl mbstring openssl pdo_sqlite sqlite3 gd exif zip pcntl session tokenizer"
if [ "$OS" != "windows" ]; then
  MODS="$("$STAGE/bin/php" -m | tr '[:upper:]' '[:lower:]')"
  MISSING=""
  for e in $REQUIRED; do echo "$MODS" | grep -qx "$e" || MISSING="$MISSING $e"; done
  [ -n "$MISSING" ] && { echo "MISSING extensions:$MISSING" >&2; exit 1; }
  echo "    all required extensions present"
fi

# --- archive --------------------------------------------------------------
echo "==> archiving"
ARCHIVE="${NAME}.tar.gz"; [ "$OS" = "windows" ] && ARCHIVE="${NAME}.zip"
( cd "$OUT_DIR" && \
  if [ "$OS" = "windows" ]; then zip -qr "$ARCHIVE" "$NAME"; else tar -czf "$ARCHIVE" "$NAME"; fi )
# Checksum the archive only (never the .sha256 itself).
( cd "$OUT_DIR" && { shasum -a 256 "$ARCHIVE" 2>/dev/null || sha256sum "$ARCHIVE"; } > "${NAME}.sha256" )
echo "==> done: ${OUT_DIR}/${ARCHIVE}"
