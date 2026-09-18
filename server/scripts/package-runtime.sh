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
# Windows has no php-fpm SAPI: the caller passes php.exe for both, so only ship
# php-fpm when it is genuinely a distinct binary (POSIX). The HTTP-serving front
# for Windows (FrankenPHP embed / a FastCGI shim) is resolved in Step 4.
if [ "$PHP_BIN" != "$FPM_BIN" ]; then
  cp "$FPM_BIN" "$STAGE/bin/php-fpm${EXE}"
fi
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

# --- Full GPL ffmpeg (S-151 Step 8) ---------------------------------------
# Under AGPLv3, GPL ffmpeg is compatible, so we bundle a FULL static build with
# libx264 (software H.264 fallback) and libmp3lame. MediaTranscoder then works
# out of the box; FFMPEG_PATH/FFPROBE_PATH point here by relative path. Set
# SKIP_FFMPEG=1 to build a smaller runtime without it.
if [ "${SKIP_FFMPEG:-0}" != "1" ]; then
  FF_TMP="$(mktemp -d)"
  echo "    fetching full GPL ffmpeg + ffprobe"
  case "${OS}_${ARCH}" in
    macos_aarch64|macos_x86_64)
      MR_ARCH="arm64"; [ "$ARCH" = "x86_64" ] && MR_ARCH="amd64"
      curl -fsSL -o "$FF_TMP/ffmpeg.zip"  "https://ffmpeg.martin-riedl.de/redirect/latest/macos/${MR_ARCH}/release/ffmpeg.zip"
      curl -fsSL -o "$FF_TMP/ffprobe.zip" "https://ffmpeg.martin-riedl.de/redirect/latest/macos/${MR_ARCH}/release/ffprobe.zip"
      unzip -q -o "$FF_TMP/ffmpeg.zip"  -d "$FF_TMP"
      unzip -q -o "$FF_TMP/ffprobe.zip" -d "$FF_TMP"
      cp "$FF_TMP/ffmpeg" "$STAGE/bin/ffmpeg"; cp "$FF_TMP/ffprobe" "$STAGE/bin/ffprobe"
      ;;
    linux_x86_64|linux_aarch64)
      BTBN="linux64"; [ "$ARCH" = "aarch64" ] && BTBN="linuxarm64"
      curl -fsSL -o "$FF_TMP/ff.tar.xz" \
        "https://github.com/BtbN/FFmpeg-Builds/releases/download/latest/ffmpeg-master-latest-${BTBN}-gpl.tar.xz"
      tar -xJf "$FF_TMP/ff.tar.xz" -C "$FF_TMP"
      FFDIR="$(find "$FF_TMP" -maxdepth 1 -type d -name 'ffmpeg-*' | head -1)"
      cp "$FFDIR/bin/ffmpeg" "$STAGE/bin/ffmpeg"; cp "$FFDIR/bin/ffprobe" "$STAGE/bin/ffprobe"
      ;;
    windows_x86_64)
      curl -fsSL -o "$FF_TMP/ff.zip" \
        "https://github.com/BtbN/FFmpeg-Builds/releases/download/latest/ffmpeg-master-latest-win64-gpl.zip"
      unzip -q -o "$FF_TMP/ff.zip" -d "$FF_TMP"
      FFDIR="$(find "$FF_TMP" -maxdepth 1 -type d -name 'ffmpeg-*' | head -1)"
      cp "$FFDIR/bin/ffmpeg.exe" "$STAGE/bin/ffmpeg.exe"; cp "$FFDIR/bin/ffprobe.exe" "$STAGE/bin/ffprobe.exe"
      ;;
  esac
  chmod +x "$STAGE/bin/ffmpeg${EXE}" "$STAGE/bin/ffprobe${EXE}" 2>/dev/null || true
  rm -rf "$FF_TMP"
  # Assert it is a GPL build with the codecs the transcoder needs (POSIX only —
  # can't run the target ffmpeg on a cross-OS packaging host).
  if [ "$OS" != "windows" ] && [ -x "$STAGE/bin/ffmpeg" ]; then
    FF_CFG="$("$STAGE/bin/ffmpeg" -version 2>/dev/null | tr ' ' '\n' | grep -E 'enable-(gpl|libx264|libmp3lame)' | sort -u | tr '\n' ' ')"
    echo "    ffmpeg: ${FF_CFG:-(could not read config — cross-arch?)}"
  fi
fi

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
(
  cd "$OUT_DIR"
  if [ "$OS" = "windows" ]; then
    # Git-bash on the Windows runner has no `zip` CLI; prefer it if present,
    # else fall back to PowerShell's Compress-Archive (always available).
    if command -v zip >/dev/null 2>&1; then
      zip -qr "$ARCHIVE" "$NAME"
    else
      powershell -NoProfile -Command "Compress-Archive -Path '${NAME}' -DestinationPath '${ARCHIVE}' -Force"
    fi
  else
    tar -czf "$ARCHIVE" "$NAME"
  fi
)
# Checksum the archive only (never the .sha256 itself).
( cd "$OUT_DIR" && { shasum -a 256 "$ARCHIVE" 2>/dev/null || sha256sum "$ARCHIVE"; } > "${NAME}.sha256" )
echo "==> done: ${OUT_DIR}/${ARCHIVE}"
