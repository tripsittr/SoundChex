#!/usr/bin/env bash
# SoundChex bundled server — remove the supervised services (S-151 Step 4).
# macOS -> launchd, Linux -> systemd. Windows: see supervisor/windows/.
set -euo pipefail
OS="$(uname -s)"
SYSTEM=0
[ "${1:-}" = "--system" ] && SYSTEM=1
if [ "$OS" = "Darwin" ]; then
  AGENTS="$HOME/Library/LaunchAgents"
  for svc in caddy fpm queue scheduler dlna; do
    t="$AGENTS/com.soundchex.$svc.plist"
    [ -f "$t" ] && { launchctl unload "$t" 2>/dev/null || true; rm -f "$t"; echo "removed com.soundchex.$svc"; }
  done
elif [ "$OS" = "Linux" ]; then
  if [ "$SYSTEM" = "1" ]; then CTL=(systemctl); DIR="/etc/systemd/system"; else CTL=(systemctl --user); DIR="${XDG_CONFIG_HOME:-$HOME/.config}/systemd/user"; fi
  for svc in caddy fpm queue scheduler dlna; do
    "${CTL[@]}" disable --now "soundchex-$svc.service" 2>/dev/null || true
    rm -f "$DIR/soundchex-$svc.service"
  done
  "${CTL[@]}" daemon-reload 2>/dev/null || true
  echo "removed systemd units"
else
  echo "Windows: run supervisor/windows/ removal (sc.exe delete SoundChex*)." >&2
fi
