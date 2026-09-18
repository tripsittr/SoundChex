SoundChex bundled server runtime
================================

This is the self-contained server runtime for SoundChex — a static PHP +
php-fpm plus Caddy, so a self-hoster runs one thing and has a working server.
No system PHP, no Herd, no three terminals.

Contents
--------
  bin/php            Static PHP CLI (artisan, queue worker, scheduler)
  bin/php-fpm        Static php-fpm (the application server)
  bin/caddy          Caddy (owns the port + TLS; serves static; proxies PHP)
  bin/cacert.pem     CA bundle — MUST stay beside bin/php (the app locates it
                     as dirname(PHP_BINARY)/cacert.pem)
  Caddyfile          Caddy config template (placeholders substituted at launch)
  php-fpm.conf       php-fpm pool template (placeholders substituted at launch)
  LICENSE            SoundChex is AGPL-3.0-or-later
  THIRD-PARTY-LICENSES.txt  Notices for PHP, Caddy + Go deps, SQLite, etc.

This runtime is normally installed and supervised by the SoundChex desktop app
or the headless installer — you do not usually run these binaries by hand. To
run it manually (development / debugging):

  1. Start php-fpm:
       bin/php-fpm --fpm-config php-fpm.conf --nodaemonize
     (substitute the {$SOUNDCHEX_*} placeholders first, or export them.)

  2. Start Caddy:
       bin/caddy run --config Caddyfile --adapter caddyfile
     Use `caddy run`, NOT `caddy start` (start hangs waiting on readiness).

The php-fpm listener is TCP (127.0.0.1:9100 by default), not a unix socket:
portable across macOS/Linux/Windows and free of the 104-char socket-path limit.

Licensing
---------
SoundChex is licensed under the GNU Affero General Public License v3.0 or later.
The bundled components keep their own licenses; see THIRD-PARTY-LICENSES.txt.
