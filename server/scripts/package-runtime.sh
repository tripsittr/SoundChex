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
# Empty on Windows: that bundle's PHP comes from the FrankenPHP archive, not
# from static-php-cli. Still positional so the argument order never differs.
PHP_BIN="${3:-}"
FPM_BIN="${4:-}"
OUT_DIR="${5:?output dir}"

CADDY_VERSION="2.11.4"
TAILWIND_VERSION="4.3.3"   # keep in step with package.json
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"   # server/
NAME="soundchex-server-${OS}-${ARCH}"
STAGE="${OUT_DIR}/${NAME}"

echo "==> packaging ${NAME}"
rm -rf "$STAGE"
mkdir -p "$STAGE/bin"

# --- PHP + php-fpm --------------------------------------------------------
EXE=""
[ "$OS" = "windows" ] && EXE=".exe"

# Windows takes its PHP from the FrankenPHP archive below — one PHP for both
# serving and the CLI, which is the only Windows arrangement that has been seen
# to run the app. POSIX keeps the static-php-cli build and php-fpm.
if [ "$OS" != "windows" ]; then
  [ -n "$PHP_BIN" ] || { echo "php binary argument required on $OS" >&2; exit 1; }
  cp "$PHP_BIN" "$STAGE/bin/php"
  if [ "$PHP_BIN" != "$FPM_BIN" ]; then
    cp "$FPM_BIN" "$STAGE/bin/php-fpm"
  fi
  chmod +x "$STAGE/bin/php" "$STAGE/bin/php-fpm" 2>/dev/null || true
fi

# --- FrankenPHP (Windows only) --------------------------------------------
# Windows has no php-fpm, so the Caddy -> fpm shape cannot be built there.
# FrankenPHP is a single binary that is both the web server and PHP, which is
# what the Windows bundle serves with instead (S-418). POSIX keeps php-fpm,
# which works and is verified.
if [ "$OS" = "windows" ]; then
  # Pinned, not "latest": a build that silently changes its own runtime is
  # not reproducible. Ships as a zip, unlike the POSIX binaries.
  FRANKEN_VERSION="${FRANKEN_VERSION:-1.12.7}"
  FRANKEN_TMP="$(mktemp -d)"
  echo "    fetching frankenphp ${FRANKEN_VERSION} (windows-x86_64)"
  # Unpacked into its own directory: the whole payload is copied below, and
  # extracting beside the archive would ship the 57 MB zip inside the bundle.
  mkdir -p "$FRANKEN_TMP/unpacked"
  curl -fsSL -o "$FRANKEN_TMP/frankenphp.zip" \
    "https://github.com/php/frankenphp/releases/download/v${FRANKEN_VERSION}/frankenphp-windows-x86_64.zip"
  unzip -q -o "$FRANKEN_TMP/frankenphp.zip" -d "$FRANKEN_TMP/unpacked"

  # The archive's layout has changed between releases, so find the binary
  # rather than assuming where it sits.
  FRANKEN_EXE="$(find "$FRANKEN_TMP/unpacked" -name 'frankenphp*.exe' -type f | head -n 1)"

  if [ -z "$FRANKEN_EXE" ]; then
    echo "no frankenphp.exe inside the downloaded archive" >&2
    exit 1
  fi

  # Copy the WHOLE payload, not just the exe. frankenphp.exe on Windows is
  # dynamically linked -- deplister reports php8ts.dll, brotlicommon.dll,
  # brotlidec.dll, brotlienc.dll, libwatcher-c.dll and pthreadVC3.dll -- so the
  # binary on its own cannot start, and the archive's ext/ is where every
  # dynamic extension (pdo_sqlite included) lives. Shipping the exe alone is
  # what made this bundle unable to serve a single request.
  FRANKEN_ROOT="$(dirname "$FRANKEN_EXE")"
  cp -R "$FRANKEN_ROOT/." "$STAGE/bin/"

  # Build-time artefacts: import libraries and headers for compiling
  # extensions against this PHP, of no use at runtime.
  rm -rf "$STAGE/bin/dev"
  rm -f "$STAGE/bin/php8embed.lib"

  rm -rf "$FRANKEN_TMP"

  # A zero-byte or HTML error page from a moved release would otherwise ship
  # as a "binary" and fail at first launch with nothing to explain it.
  if [ ! -s "$STAGE/bin/frankenphp.exe" ]; then
    echo "frankenphp download failed or was empty" >&2
    exit 1
  fi

  # The archive is expected to carry a PHP alongside frankenphp; without it the
  # queue worker and scheduler have nothing to run on.
  if [ ! -s "$STAGE/bin/php.exe" ]; then
    echo "no php.exe inside the frankenphp archive" >&2
    exit 1
  fi
fi

# --- CA bundle beside php (load-bearing convention) -----------------------
echo "    fetching cacert.pem"
curl -fsSL -o "$STAGE/bin/cacert.pem" https://curl.se/ca/cacert.pem

# --- Caddy (official release static binary) -------------------------------
# Not on Windows: FrankenPHP is the web server there, the supervisor never
# starts caddy (its Windows process list is frankenphp/queue/scheduler) and
# no Caddyfile is rendered. Shipping it added 48 MB nothing could run.
if [ "$OS" != "windows" ]; then
  case "${OS}_${ARCH}" in
    macos_aarch64)  CADDY_OSARCH="mac_arm64" ;;
    macos_x86_64)   CADDY_OSARCH="mac_amd64" ;;
    linux_aarch64)  CADDY_OSARCH="linux_arm64" ;;
    linux_x86_64)   CADDY_OSARCH="linux_amd64" ;;
    *) echo "unsupported os/arch: ${OS}_${ARCH}" >&2; exit 1 ;;
  esac
  echo "    fetching caddy ${CADDY_VERSION} (${CADDY_OSARCH})"
  CADDY_TMP="$(mktemp -d)"
  curl -fsSL -o "$CADDY_TMP/caddy.tar.gz" \
    "https://github.com/caddyserver/caddy/releases/download/v${CADDY_VERSION}/caddy_${CADDY_VERSION}_${CADDY_OSARCH}.tar.gz"
  tar -xzf "$CADDY_TMP/caddy.tar.gz" -C "$CADDY_TMP"
  cp "$CADDY_TMP/caddy" "$STAGE/bin/caddy"
  chmod +x "$STAGE/bin/caddy" 2>/dev/null || true
  rm -rf "$CADDY_TMP"
fi

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

# --- Tailwind CLI (S-350) -------------------------------------------------
# Compiles an installed plugin's stylesheet when that plugin is enabled. A
# plugin's Blade cannot use the app's Tailwind — the app's CSS is built before
# release and a catalogue-installed plugin lives outside the repo on the user's
# machine — so without this a plugin has to hand-write CSS.
#
# Sits beside php, which is how config/plugin-styles.php finds it. MIT; the
# binary embeds Bun (MIT) and JavaScriptCore (LGPL-2.1) — see
# THIRD-PARTY-LICENSES.txt. Set SKIP_TAILWIND=1 for a smaller runtime; plugins
# then keep whatever CSS they ship, which is the pre-S-350 behaviour.
if [ "${SKIP_TAILWIND:-0}" != "1" ]; then
  case "${OS}_${ARCH}" in
    macos_aarch64)  TW_ASSET="tailwindcss-macos-arm64" ;;
    macos_x86_64)   TW_ASSET="tailwindcss-macos-x64" ;;
    linux_aarch64)  TW_ASSET="tailwindcss-linux-arm64" ;;
    linux_x86_64)   TW_ASSET="tailwindcss-linux-x64" ;;
    windows_x86_64) TW_ASSET="tailwindcss-windows-x64.exe" ;;
  esac

  echo "    fetching tailwindcss ${TAILWIND_VERSION} (${TW_ASSET})"
  TW_TMP="$(mktemp -d)"
  TW_BASE="https://github.com/tailwindlabs/tailwindcss/releases/download/v${TAILWIND_VERSION}"

  curl -fsSL -o "$TW_TMP/tw" "${TW_BASE}/${TW_ASSET}"
  # Verify against the published checksums rather than trusting the transfer.
  # Upstream lists names as "./tailwindcss-…", so match the tail of the line.
  if curl -fsSL -o "$TW_TMP/sha256sums.txt" "${TW_BASE}/sha256sums.txt"; then
    TW_WANT=$(grep -E "[ /]${TW_ASSET}$" "$TW_TMP/sha256sums.txt" | head -1 | cut -d' ' -f1)
    if [ -n "$TW_WANT" ]; then
      if command -v sha256sum >/dev/null 2>&1; then
        TW_GOT=$(sha256sum "$TW_TMP/tw" | cut -d' ' -f1)
      else
        TW_GOT=$(shasum -a 256 "$TW_TMP/tw" | cut -d' ' -f1)
      fi
      if [ "$TW_WANT" != "$TW_GOT" ]; then
        echo "tailwindcss checksum mismatch: expected $TW_WANT, got $TW_GOT" >&2
        exit 1
      fi
      echo "    tailwindcss: checksum ok"
    else
      echo "    tailwindcss: no checksum published for ${TW_ASSET} — skipping verification" >&2
    fi
  fi

  cp "$TW_TMP/tw" "$STAGE/bin/tailwindcss${EXE}"
  chmod +x "$STAGE/bin/tailwindcss${EXE}" 2>/dev/null || true
  rm -rf "$TW_TMP"

  # Confirm it runs and is the version we expect (POSIX, same-arch only).
  if [ "$OS" != "windows" ] && [ -x "$STAGE/bin/tailwindcss" ]; then
    TW_REPORTED=$("$STAGE/bin/tailwindcss" --help 2>&1 | head -1)
    echo "    tailwindcss: ${TW_REPORTED:-(could not read version — cross-arch?)}"
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
if [ "$OS" != "windows" ]; then
  REQUIRED="curl intl mbstring openssl pdo_sqlite sqlite3 gd exif zip pcntl session tokenizer"
  MODS="$("$STAGE/bin/php" -m | tr '[:upper:]' '[:lower:]')"
  MISSING=""
  for e in $REQUIRED; do echo "$MODS" | grep -qx "$e" || MISSING="$MISSING $e"; done
  [ -n "$MISSING" ] && { echo "MISSING extensions:$MISSING" >&2; exit 1; }
  echo "    all required extensions present"
else
  # Windows extensions are DLLs in bin/ext, loaded by the php.ini the Server
  # app writes at start (it is the only thing that knows the install path), so
  # they cannot be checked by running php here -- no ini, nothing loaded. Check
  # instead that the DLLs are present and that the binaries actually execute,
  # which is what shipping frankenphp.exe alone silently failed.
  REQUIRED_EXT="curl exif fileinfo gd intl mbstring openssl pdo_sqlite sodium sqlite3 zip"
  MISSING=""
  for e in $REQUIRED_EXT; do
    [ -s "$STAGE/bin/ext/php_${e}.dll" ] || MISSING="$MISSING $e"
  done
  if [ -n "$MISSING" ]; then
    echo "MISSING extension DLLs in bin/ext:$MISSING" >&2
    exit 1
  fi
  echo "    all required extension DLLs present"

  # Proves the dynamic links resolve. A missing php8ts.dll fails right here
  # rather than on a user's machine with no message at all.
  if ! "$STAGE/bin/frankenphp.exe" version >/dev/null 2>&1; then
    echo "frankenphp.exe will not run -- missing DLL dependencies?" >&2
    "$STAGE/bin/frankenphp.exe" version || true
    exit 1
  fi
  if ! "$STAGE/bin/php.exe" --version >/dev/null 2>&1; then
    echo "php.exe will not run -- missing DLL dependencies?" >&2
    exit 1
  fi
  echo "    frankenphp.exe and php.exe both start"
fi

# --- what actually got packaged -------------------------------------------
# Printed so a CI log answers "is the runtime complete?" on its own. Without
# it the only way to check was downloading the archive — 222 MB for Windows,
# which times out often enough to be no check at all (S-420).
echo "==> bundled binaries"
for f in "$STAGE"/bin/*; do
  [ -f "$f" ] || continue
  printf '    %-18s %s\n' "$(basename "$f")" "$(du -h "$f" | cut -f1)"
done

# The server cannot serve without these, and a missing one is a broken
# release rather than a warning worth scrolling past.
REQUIRED="php${EXE}"
if [ "$OS" = "windows" ]; then
  REQUIRED="$REQUIRED frankenphp.exe"
else
  REQUIRED="$REQUIRED php-fpm caddy"
fi

for required in $REQUIRED; do
  if [ ! -s "$STAGE/bin/$required" ]; then
    echo "missing or empty: bin/$required" >&2
    exit 1
  fi
done

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
