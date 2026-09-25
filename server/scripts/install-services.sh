#!/usr/bin/env bash
# SoundChex bundled server — install the supervised services (S-151 Step 4).
#
# Installs Caddy + php-fpm + queue + scheduler + DLNA discovery as OS services that (a) run the
# BUNDLED binaries by relative path (no Herd, no system PHP) and (b) start at
# boot and restart on crash. macOS -> launchd, Linux -> systemd. Windows has its
# own script (server/supervisor/windows/), since it needs a different serving
# front (no php-fpm SAPI).
#
# Layout it assumes (all relative to --runtime, which defaults to this bundle):
#   <runtime>/bin/{php,php-fpm,caddy,cacert.pem}
#   <config>/Caddyfile, <config>/php-fpm.conf   (templates, placeholders filled)
#
# Usage:
#   install-services.sh --app /path/to/app [--runtime DIR] [--listen :8000]
#                       [--user NAME] [--system]
set -euo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"   # server/
APP_DIR=""
RUNTIME_DIR="$HERE"                # the bundle (bin/ lives here by default)
LISTEN=":8000"
RUN_AS_USER=""
SYSTEM=0

while [ $# -gt 0 ]; do
  case "$1" in
    --app) APP_DIR="$2"; shift 2 ;;
    --runtime) RUNTIME_DIR="$2"; shift 2 ;;
    --listen) LISTEN="$2"; shift 2 ;;
    --user) RUN_AS_USER="$2"; shift 2 ;;
    --system) SYSTEM=1; shift ;;
    *) echo "unknown arg: $1" >&2; exit 2 ;;
  esac
done

[ -n "$APP_DIR" ] || { echo "--app /path/to/app is required" >&2; exit 2; }
[ -x "$RUNTIME_DIR/bin/php-fpm" ] || { echo "no php-fpm at $RUNTIME_DIR/bin" >&2; exit 2; }

OS="$(uname -s)"
APP_PUBLIC="$APP_DIR/public"

# Per-OS writable locations for config/run/log/data.
case "$OS" in
  Darwin)
    BASE="$HOME/Library/Application Support/SoundChex"
    LOG_DIR="$HOME/Library/Logs/SoundChex"
    ;;
  Linux)
    if [ "$SYSTEM" = "1" ]; then
      BASE="/var/lib/soundchex"; LOG_DIR="/var/log/soundchex"
    else
      BASE="${XDG_DATA_HOME:-$HOME/.local/share}/soundchex"; LOG_DIR="$BASE/log"
    fi
    ;;
  *) echo "unsupported OS: $OS (Windows: use server/supervisor/windows/)" >&2; exit 2 ;;
esac
CONFIG_DIR="$BASE/config"; RUN_DIR="$BASE/run"; DATA_DIR="$BASE/data"
mkdir -p "$CONFIG_DIR" "$RUN_DIR" "$DATA_DIR" "$LOG_DIR"

# Render the runtime's Caddyfile / php-fpm.conf templates into CONFIG_DIR,
# substituting the {$SOUNDCHEX_*} placeholders the templates use. The services
# also pass these as env, but baking them in keeps a hand-run reproducible.
# The pool needs a user/group when php-fpm's master runs as root (a system
# service). Default to the invoking user on macOS (agents run unprivileged) and
# to nobody/nogroup on a Linux system install; a --user overrides either.
if [ "$OS" = "Darwin" ]; then
  FPM_USER="${RUN_AS_USER:-$(id -un)}"; FPM_GROUP="$(id -gn "$FPM_USER" 2>/dev/null || echo staff)"
else
  FPM_USER="${RUN_AS_USER:-nobody}"; FPM_GROUP="nogroup"
fi

render_config() {
  sed \
    -e "s#{\$SOUNDCHEX_ROOT}#$APP_PUBLIC#g" \
    -e "s#{\$SOUNDCHEX_FPM}#127.0.0.1:9100#g" \
    -e "s#{\$SOUNDCHEX_LISTEN}#$LISTEN#g" \
    -e "s#{\$SOUNDCHEX_LOG}#$LOG_DIR/caddy-access.log#g" \
    -e "s#{\$SOUNDCHEX_RUN}#$RUN_DIR#g" \
    -e "s#{\$SOUNDCHEX_USER}#$FPM_USER#g" \
    -e "s#{\$SOUNDCHEX_GROUP}#$FPM_GROUP#g" \
    "$1"
}
render_config "$HERE/templates/Caddyfile" > "$CONFIG_DIR/Caddyfile"
render_config "$HERE/templates/php-fpm.conf" > "$CONFIG_DIR/php-fpm.conf"

# cacert.pem must sit beside the php binary (TransferReceiver convention);
# the bundle already ships it there. Nothing to do but assert it.
[ -f "$RUNTIME_DIR/bin/cacert.pem" ] || echo "warning: no cacert.pem beside php" >&2

fill() {  # fill a supervisor template's {{...}} placeholders
  sed \
    -e "s#{{RUNTIME_DIR}}#$RUNTIME_DIR#g" \
    -e "s#{{APP_DIR}}#$APP_DIR#g" \
    -e "s#{{APP_PUBLIC}}#$APP_PUBLIC#g" \
    -e "s#{{CONFIG_DIR}}#$CONFIG_DIR#g" \
    -e "s#{{RUN_DIR}}#$RUN_DIR#g" \
    -e "s#{{LOG_DIR}}#$LOG_DIR#g" \
    -e "s#{{DATA_DIR}}#$DATA_DIR#g" \
    -e "s#{{LISTEN}}#$LISTEN#g" \
    -e "s#{{USER_LINE}}#${RUN_AS_USER:+User=$RUN_AS_USER}#g" \
    "$1"
}

if [ "$OS" = "Darwin" ]; then
  AGENTS="$HOME/Library/LaunchAgents"; mkdir -p "$AGENTS"
  for svc in fpm caddy queue scheduler dlna; do
    label="com.soundchex.$svc"
    target="$AGENTS/$label.plist"
    fill "$HERE/supervisor/launchd/$label.plist.template" > "$target"
    launchctl unload "$target" 2>/dev/null || true
    launchctl load "$target"
    echo "loaded $label"
  done
  echo "macOS services installed (launchd). Listening on $LISTEN."
else
  # Linux: systemd. System-wide needs root; user units run in the login session.
  if [ "$SYSTEM" = "1" ]; then
    UNIT_DIR="/etc/systemd/system"; CTL=(systemctl)
  else
    UNIT_DIR="${XDG_CONFIG_HOME:-$HOME/.config}/systemd/user"; CTL=(systemctl --user)
    mkdir -p "$UNIT_DIR"
  fi
  for svc in fpm caddy queue scheduler dlna; do
    unit="soundchex-$svc.service"
    fill "$HERE/supervisor/systemd/$unit.template" > "$UNIT_DIR/$unit"
    echo "wrote $UNIT_DIR/$unit"
  done
  "${CTL[@]}" daemon-reload
  for svc in fpm caddy queue scheduler dlna; do
    "${CTL[@]}" enable --now "soundchex-$svc.service" || true
  done
  echo "Linux services installed (systemd). Listening on $LISTEN."
fi
