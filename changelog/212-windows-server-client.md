# Windows can host a library

S-418 and S-419.

Windows was a client-only platform, and unverified even as that. Three things
stood between it and hosting a library, all found by reading the platform
branches rather than running anything — nobody has ever run the Windows binary.

## It could not serve HTTP at all

PHP ships no `php-fpm` SAPI on Windows, and `static-php-cli` builds only CLI
there. The whole macOS and Linux arrangement is Caddy proxying to php-fpm, and
it simply does not port: the Windows bundle shipped `php.exe` and Caddy, and
nothing that answers a request.

Windows now serves with **FrankenPHP** — a single binary that is both the web
server and PHP, so it replaces php-fpm *and* Caddy rather than sitting between
them. The supervisor runs `frankenphp, queue, scheduler` there against
`php-fpm, caddy, queue, scheduler` everywhere else, and skips rendering a
Caddyfile and an fpm pool that FrankenPHP does not read.

macOS and Linux are untouched. They work and are verified; there was no reason
to move them.

## It logged to a directory that does not exist

`logs_directory()` read `$HOME` and branched macOS versus everything-else,
sending Windows logs to `~/.local/share/soundchex/logs`. Windows has neither
`HOME` nor `~/.local`, so the path resolved to a relative one and the logs
landed wherever the process happened to start. "Open logs" opened nothing.

Now `%LOCALAPPDATA%\SoundChex\logs`, falling back to `%USERPROFILE%`.

## It did not survive a reboot

macOS installs launchd agents so the server restarts at login. Windows had no
equivalent, so a server there stopped at the first restart and stayed stopped.

There is now an autostart command writing a `Run` key. Deliberately not a true
Windows service: a service runs with no desktop session and cannot show the
window this app *is*. The Run key starts it at login as the user, which is what
a library on a personal machine actually wants.

## A bug caught while wiring it

Registering the new commands in a second `invoke_handler` call would have
**unregistered every existing command on Windows** — Tauri replaces the handler
rather than appending to it. They are in the one list, with non-Windows stubs
so the signature is identical everywhere.

## Verified, and not

- The FrankenPHP download was **run for real**: the pinned 1.12.7 release
  downloads, extracts and is a genuine PE32+ Windows executable. My first
  attempt used a version that 404s and assumed a bare `.exe` where the project
  ships a `.zip` — both would have failed in CI.
- The two process lists are consistent, and every process named in either has
  a spawn arm.
- Rust builds; 1,112 PHP tests pass.

**None of it has run on Windows.** There is no Windows machine here and no
cross-compile target installed, so the Windows-only branches are compiled by CI
and nothing more. The FrankenPHP arguments in particular are checked against
the binary's own strings, not against a running server.
