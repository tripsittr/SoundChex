#!/usr/bin/env bash
# SoundChex — standalone headless installer (S-151 Step 6).
#
# For a box with no GUI — a NAS, a home server. It drops the bundled runtime
# (PHP + php-fpm + Caddy, no system PHP needed), prepares the app, and registers
# the services (reusing install-services.sh from Step 4). "Plug and play": the
# only prerequisites are curl and tar, which every server has.
#
# It uses the BUNDLED php for every PHP step (composer, artisan, migrate), so the
# host needs no PHP of its own — that is the whole point.
#
# Usage:
#   install-headless.sh --app /opt/soundchex/app [--version latest]
#                       [--listen :8000] [--user soundchex] [--system]
#                       [--runtime-url URL | --runtime-file PATH]
#
# --app        where the SoundChex application code already is (a checkout or an
#              unpacked release). Required.
# --version    the server runtime release to fetch (default: latest).
# --system     install system-wide services (needs root); else per-user.
set -euo pipefail

REPO="tripsittr/SoundChex"
APP_DIR=""
VERSION="latest"
LISTEN=":8000"
RUN_AS_USER=""
SYSTEM=0
RUNTIME_URL=""
RUNTIME_FILE=""

while [ $# -gt 0 ]; do
  case "$1" in
    --app) APP_DIR="$2"; shift 2 ;;
    --version) VERSION="$2"; shift 2 ;;
    --listen) LISTEN="$2"; shift 2 ;;
    --user) RUN_AS_USER="$2"; shift 2 ;;
    --system) SYSTEM=1; shift ;;
    --runtime-url) RUNTIME_URL="$2"; shift 2 ;;
    --runtime-file) RUNTIME_FILE="$2"; shift 2 ;;
    *) echo "unknown arg: $1" >&2; exit 2 ;;
  esac
done

[ -n "$APP_DIR" ] || { echo "--app /path/to/app is required" >&2; exit 2; }
[ -f "$APP_DIR/artisan" ] || { echo "no artisan at $APP_DIR — is that the app dir?" >&2; exit 2; }
command -v curl >/dev/null || { echo "curl is required" >&2; exit 2; }
command -v tar  >/dev/null || { echo "tar is required" >&2; exit 2; }

# --- Work out which runtime bundle this host needs ------------------------
OS="$(uname -s)"; ARCH="$(uname -m)"
case "$OS" in Darwin) OSN=macos ;; Linux) OSN=linux ;; *) echo "unsupported OS: $OS" >&2; exit 2 ;; esac
case "$ARCH" in arm64|aarch64) ARCHN=aarch64 ;; x86_64|amd64) ARCHN=x86_64 ;; *) echo "unsupported arch: $ARCH" >&2; exit 2 ;; esac
ASSET="soundchex-server-${OSN}-${ARCHN}.tar.gz"

RUNTIME_PARENT="$APP_DIR/../runtime"
mkdir -p "$RUNTIME_PARENT"
TARBALL=""

if [ -n "$RUNTIME_FILE" ]; then
  TARBALL="$RUNTIME_FILE"
else
  if [ -z "$RUNTIME_URL" ]; then
    if [ "$VERSION" = "latest" ]; then
      RUNTIME_URL="https://github.com/$REPO/releases/latest/download/$ASSET"
    else
      RUNTIME_URL="https://github.com/$REPO/releases/download/$VERSION/$ASSET"
    fi
  fi
  echo "==> fetching runtime: $RUNTIME_URL"
  TARBALL="$RUNTIME_PARENT/$ASSET"
  curl -fSL -o "$TARBALL" "$RUNTIME_URL"
  # Verify the checksum if the .sha256 is published beside it.
  if curl -fsSL -o "$TARBALL.sha256" "${RUNTIME_URL}.sha256" 2>/dev/null; then
    echo "==> verifying checksum"
    ( cd "$RUNTIME_PARENT" && { shasum -a 256 -c "$(basename "$TARBALL").sha256" 2>/dev/null \
        || sha256sum -c "$(basename "$TARBALL").sha256"; } )
  fi
fi

echo "==> unpacking runtime"
tar -xzf "$TARBALL" -C "$RUNTIME_PARENT"
RUNTIME_DIR="$RUNTIME_PARENT/soundchex-server-${OSN}-${ARCHN}"
[ -x "$RUNTIME_DIR/bin/php" ] || { echo "runtime unpack looks wrong: no bin/php" >&2; exit 2; }
PHP="$RUNTIME_DIR/bin/php"

# --- Prepare the app using the BUNDLED php (no system PHP) -----------------
cd "$APP_DIR"

if [ ! -f .env ]; then
  [ -f .env.example ] && cp .env.example .env
  echo "==> generating APP_KEY"
  "$PHP" artisan key:generate --force
fi

if [ -f composer.json ] && [ ! -d vendor ]; then
  if [ -f "$RUNTIME_DIR/bin/composer.phar" ]; then
    echo "==> installing composer dependencies (bundled composer)"
    "$PHP" "$RUNTIME_DIR/bin/composer.phar" install --no-dev --optimize-autoloader --no-interaction
  elif command -v composer >/dev/null; then
    echo "==> installing composer dependencies (system composer + bundled php)"
    "$PHP" "$(command -v composer)" install --no-dev --optimize-autoloader --no-interaction
  else
    echo "!! composer not found and none bundled — vendor/ must be shipped in the app dir." >&2
  fi
fi

echo "==> migrating the database"
"$PHP" artisan migrate --force

echo "==> checking the runtime's extensions"
"$PHP" artisan server:check-extensions || {
  echo "!! the runtime is missing required extensions (see above). Aborting." >&2
  exit 1
}

# APP_URL to a real address of this machine, zero-config.
"$PHP" artisan server:detect-address || true

# --- Register the services (Step 4 installer) -----------------------------
echo "==> installing services"
ARGS=(--app "$APP_DIR" --runtime "$RUNTIME_DIR" --listen "$LISTEN")
[ -n "$RUN_AS_USER" ] && ARGS+=(--user "$RUN_AS_USER")
[ "$SYSTEM" = "1" ] && ARGS+=(--system)
"$(dirname "${BASH_SOURCE[0]}")/install-services.sh" "${ARGS[@]}"

echo ""
echo "==> SoundChex is installed and running."
echo "    App:      $APP_DIR"
echo "    Runtime:  $RUNTIME_DIR"
echo "    Serving:  $LISTEN"
echo "    Remove:   $(dirname "${BASH_SOURCE[0]}")/uninstall-services.sh"
